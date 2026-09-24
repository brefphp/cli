<?php declare(strict_types=1);

namespace Bref\Cli\Commands;

use Bref\Cli\Cli\IO;
use Bref\Cli\Cli\LogRenderer;
use Bref\Cli\Cli\OutputMode;
use Bref\Cli\Cli\Styles;
use Bref\Cli\Cli\TimeRange;
use Exception;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TimeoutExceptionInterface;

/**
 * @phpstan-import-type LogRecord from LogRenderer
 */
class Logs extends EnvironmentDataCommand
{
    protected function configure(): void
    {
        $this
            ->setName('logs')
            ->setDescription('Show the logs of an environment')
            ->addOption('since', null, InputOption::VALUE_REQUIRED, 'Start of the time range: a duration ago (30m, 2h, 7d) or a date (2026-09-23, "2026-09-23 14:30")', '1h')
            ->addOption('until', null, InputOption::VALUE_REQUIRED, 'End of the time range, in the same formats (default: now)')
            ->addOption('search', 's', InputOption::VALUE_REQUIRED, 'Only the lines that contain all these words, in any order (case-insensitive)')
            ->addOption('regex', null, InputOption::VALUE_NONE, 'Search with a regular expression instead of words')
            ->addOption('function', 'f', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Only the logs of this function, e.g. web (can be repeated)')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum number of lines, the most recent ones (up to 1000)', '100')
            ->addOption('all', null, InputOption::VALUE_NONE, 'Include the lines that Lambda and PHP-FPM write on every invocation (START, END, REPORT...)')
            ->addOption('full', null, InputOption::VALUE_NONE, 'Show long messages in full and the stack traces of exceptions')
            ->setHelp(<<<'HELP'
                Shows the logs of all the functions of an environment, oldest first. Times are in UTC.

                Laravel logs show their level and context, and exceptions show their class and location
                (their stack trace with --full). Other logs are shown as they are, one line per entry.
                The lines that Lambda and PHP-FPM write on every invocation are hidden, --all shows them.

                Examples:

                  <info>bref logs --env=prod</info>                                    the last hour
                  <info>bref logs --env=prod --since=1d --search="payment failed"</info>
                  <info>bref logs --env=prod --function=web --search="timeout|memory" --regex</info>
                  <info>bref logs --env=prod --since="2026-09-23 14:00" --until="2026-09-23 15:00"</info>
                  <info>bref logs --env=prod --since=10m --full</info>                    with stack traces
                  <info>bref logs --env=prod --app=my-app --team=my-team</info>           outside the project directory
                HELP);

        parent::configure();
    }

    protected function show(InputInterface $input, OutputInterface $output): int
    {
        $query = $this->query($input);
        $environmentId = $this->findEnvironmentId($input);

        $human = OutputMode::isHuman($output);
        if ($human) {
            IO::spin('searching logs');
        }
        try {
            $result = $this->brefCloud()->getLogs($environmentId, $query);
        } catch (TimeoutExceptionInterface|ServerExceptionInterface $e) {
            throw new Exception('The log search took too long or failed. Narrow the time range with --since and --until, or filter with --search or --function.', previous: $e);
        } finally {
            if ($human) {
                IO::spinClear();
            }
        }

        if ($input->getOption('json')) {
            $this->writeJson($output, $result);
            return 0;
        }

        $renderer = new LogRenderer(colors: $human, full: (bool) $input->getOption('full'));
        foreach ($renderer->render($result['records']) as $line) {
            $output->writeln($line, OutputInterface::OUTPUT_RAW);
        }

        $summary = $this->summary($result, $query);
        $this->stderr($output)->writeln($human ? Styles::gray($summary) : $summary, OutputInterface::OUTPUT_RAW);

        return 0;
    }

    /**
     * @return array{since: int, until?: int, search?: string, regex?: bool, functions?: list<string>, limit: int, all?: bool, full?: bool}
     */
    private function query(InputInterface $input): array
    {
        $now = time();
        /** @var string $since */
        $since = $input->getOption('since');
        $query = ['since' => TimeRange::parse($since, $now)];
        $until = $input->getOption('until');
        if (is_string($until)) {
            $query['until'] = TimeRange::parse($until, $now);
        }
        $search = $input->getOption('search');
        if (is_string($search) && $search !== '') {
            $query['search'] = $search;
            $query['regex'] = (bool) $input->getOption('regex');
        }
        /** @var list<string> $functions */
        $functions = $input->getOption('function');
        if ($functions) {
            $query['functions'] = $functions;
        }
        $limit = $input->getOption('limit');
        if (! is_numeric($limit) || (int) $limit < 1) {
            throw new Exception('The --limit option must be a positive number.');
        }
        $query['limit'] = (int) $limit;
        if ($input->getOption('all')) {
            $query['all'] = true;
        }
        if ($input->getOption('full')) {
            $query['full'] = true;
        }

        return $query;
    }

    /**
     * @param array{from: string, to: string, limit: int, has_more: bool, records: list<LogRecord>} $result
     * @param array{search?: string, full?: bool} $query
     */
    private function summary(array $result, array $query): string
    {
        $range = sprintf('between %s and %s UTC', $this->date($result['from']), $this->date($result['to']));
        $count = count($result['records']);
        if ($count === 0) {
            return "No logs $range" . (isset($query['search']) ? ' matching the search' : '') . '.';
        }

        if ($result['has_more']) {
            // Fewer lines than the limit: Bref Cloud stopped at its maximum response size
            $raiseLimit = $count >= $result['limit'] ? ', or raise --limit' : '';
            $summary = "The $count most recent lines $range, more lines match: narrow with --since, --search or --function$raiseLimit.";
        } else {
            $summary = "$count " . ($count === 1 ? 'line' : 'lines') . " $range.";
        }
        if (! isset($query['full']) && $this->hasTruncatedContent($result['records'])) {
            $summary .= ' --full shows long messages in full and stack traces.';
        }

        return $summary;
    }

    /**
     * @param list<LogRecord> $records
     */
    private function hasTruncatedContent(array $records): bool
    {
        foreach ($records as $record) {
            if (isset($record['exception']) || preg_match('/…\(\+\d+ chars\)$/u', $record['message']) === 1) {
                return true;
            }
        }

        return false;
    }

    private function date(string $timestamp): string
    {
        return substr(str_replace('T', ' ', $timestamp), 0, 16);
    }
}
