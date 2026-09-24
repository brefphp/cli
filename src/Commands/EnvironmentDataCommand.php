<?php declare(strict_types=1);

namespace Bref\Cli\Commands;

use Bref\Cli\Application;
use Bref\Cli\BrefCloudClient;
use Bref\Cli\Cli\OutputMode;
use DateTimeImmutable;
use Exception;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * Shows data of an environment stored in Bref Cloud (logs, deployments), for humans and AI agents.
 */
abstract class EnvironmentDataCommand extends ApplicationCommand
{
    public function __construct(
        private readonly ?BrefCloudClient $brefCloud = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('app', null, InputOption::VALUE_REQUIRED, 'The application name, to use with --team when there is no config file')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output JSON');

        parent::configure();
    }

    abstract protected function show(InputInterface $input, OutputInterface $output): int;

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // The data is written directly, not through IO::writeln(): same workaround as in IO::safeWrite(),
        // Symfony's output silently truncates what it writes to a non-blocking stream
        stream_set_blocking(STDOUT, true);
        stream_set_blocking(STDERR, true);

        if (! $input->getOption('json')) {
            return $this->show($input, $output);
        }

        // A program reading JSON cannot parse the error rendered for humans
        try {
            return $this->show($input, $output);
        } catch (Throwable $e) {
            $this->writeJson($output, ['error' => ['message' => Application::prettifyException($e)->getMessage()]]);

            return 1;
        }
    }

    protected function brefCloud(): BrefCloudClient
    {
        return $this->brefCloud ?? new BrefCloudClient;
    }

    /**
     * @return int The environment ID.
     */
    protected function findEnvironmentId(InputInterface $input): int
    {
        ['appName' => $appName, 'environmentName' => $environmentName, 'team' => $team] = $this->parseEnvironmentOptions($input);

        return $this->brefCloud()->findEnvironment($team, $appName, $environmentName)['id'];
    }

    /**
     * The deployment passed as argument, or else the latest deployment of the environment.
     */
    protected function deploymentId(InputInterface $input): int
    {
        $id = $input->getArgument('id');
        if ($id !== null) {
            if (! is_string($id) || ! ctype_digit($id)) {
                throw new Exception('The deployment ID must be a number, for example 42.');
            }
            return (int) $id;
        }

        $latest = $this->brefCloud()->listDeployments($this->findEnvironmentId($input), 1)[0] ?? null;
        if (! $latest) {
            throw new Exception('This environment has not been deployed yet.');
        }

        return $latest['id'];
    }

    protected function formatDate(?string $date): string
    {
        return $date ? (new DateTimeImmutable($date))->format('Y-m-d H:i') . ' UTC' : '';
    }

    protected function formatDuration(?string $start, ?string $end): string
    {
        if (! $start || ! $end) {
            return '';
        }
        $seconds = (new DateTimeImmutable($end))->getTimestamp() - (new DateTimeImmutable($start))->getTimestamp();

        return $seconds >= 60 ? sprintf('%dm %02ds', intdiv($seconds, 60), $seconds % 60) : "{$seconds}s";
    }

    protected function writeJson(OutputInterface $output, mixed $data): void
    {
        $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR;
        if (OutputMode::isHuman($output)) {
            $flags |= JSON_PRETTY_PRINT;
        }
        $output->writeln(json_encode($data, $flags), OutputInterface::OUTPUT_RAW);
    }

    /**
     * Where to write what is not the data itself (summaries, hints), so that it can be piped.
     */
    protected function stderr(OutputInterface $output): OutputInterface
    {
        return $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
    }
}
