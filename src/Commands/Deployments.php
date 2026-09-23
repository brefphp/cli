<?php declare(strict_types=1);

namespace Bref\Cli\Commands;

use Bref\Cli\Cli\OutputMode;
use Bref\Cli\Cli\Styles;
use Exception;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class Deployments extends EnvironmentDataCommand
{
    protected function configure(): void
    {
        $this
            ->setName('deployments')
            ->setDescription('List the latest deployments of an environment')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Number of deployments (up to 100)', '10')
            ->setHelp(<<<'HELP'
                Lists the latest deployments of an environment, the most recent first.
                The output is JSON when the CLI is not run by a human in a terminal (for example by an AI agent).

                Examples:

                  <info>bref deployments --env=prod</info>
                  <info>bref deployments:show --env=prod</info>        details of the latest deployment
                  <info>bref deployments:logs 42</info>                logs of deployment 42
                HELP);

        parent::configure();
    }

    protected function show(InputInterface $input, OutputInterface $output): int
    {
        $limit = $input->getOption('limit');
        if (! is_numeric($limit) || (int) $limit < 1) {
            throw new Exception('The --limit option must be a positive number.');
        }

        $deployments = $this->brefCloud()->listDeployments($this->findEnvironmentId($input), (int) $limit);

        if ($input->getOption('json') || ! OutputMode::isHuman($output)) {
            $this->writeJson($output, $deployments);
            return 0;
        }

        if (! $deployments) {
            $output->writeln('This environment has not been deployed yet.');
            return 0;
        }
        $table = new Table($output);
        $table->setStyle('compact');
        foreach ($deployments as $deployment) {
            $table->addRow([
                Styles::gray("#{$deployment['id']}"),
                $this->status($deployment['status'], $deployment['message']),
                $this->formatDate($deployment['created_at']),
                $this->formatDuration($deployment['created_at'], $deployment['finished_at']),
                Styles::gray(substr((string) $deployment['git_ref'], 0, 7)),
                // Escaped: the table interprets console tags like <error>
                OutputFormatter::escape(mb_strimwidth(strtok((string) $deployment['git_message'], "\n") ?: '', 0, 60, '…')),
                Styles::gray(OutputFormatter::escape((string) $deployment['author'])),
            ]);
        }
        $table->render();

        return 0;
    }

    private function status(string $status, string $label): string
    {
        return match ($status) {
            'success' => Styles::green($label),
            'failed' => Styles::red($label),
            'queued', 'deploying' => Styles::yellow($label),
            default => $label,
        };
    }
}
