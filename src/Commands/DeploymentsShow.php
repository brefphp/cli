<?php declare(strict_types=1);

namespace Bref\Cli\Commands;

use Bref\Cli\Cli\OutputMode;
use Bref\Cli\Cli\Styles;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class DeploymentsShow extends EnvironmentDataCommand
{
    protected function configure(): void
    {
        $this
            ->setName('deployments:show')
            ->setDescription('Show the details of a deployment')
            ->addArgument('id', InputArgument::OPTIONAL, 'The deployment ID (default: the latest deployment of the environment)')
            ->setHelp(<<<'HELP'
                Shows the status, git commit, author and error of a deployment.
                The output is JSON when the CLI is not run by a human in a terminal (for example by an AI agent).

                Examples:

                  <info>bref deployments:show --env=prod</info>        the latest deployment of the environment
                  <info>bref deployments:show 42</info>                works outside the project directory
                  <info>bref deployments:logs 42</info>                the logs of the deployment
                HELP);

        parent::configure();
    }

    protected function show(InputInterface $input, OutputInterface $output): int
    {
        $deployment = $this->brefCloud()->getDeployment($this->deploymentId($input));
        // Shown by `deployments:logs`
        unset($deployment['logs']);

        if ($input->getOption('json') || ! OutputMode::isHuman($output)) {
            $this->writeJson($output, $deployment);
            return 0;
        }

        $environment = isset($deployment['app'], $deployment['environment'])
            ? "{$deployment['app']['name']} / {$deployment['environment']['name']}"
            : '';
        $status = match ($deployment['status']) {
            'success' => Styles::green($deployment['message']),
            'failed' => Styles::red($deployment['message']),
            default => Styles::yellow($deployment['message']),
        };
        $duration = $this->formatDuration($deployment['created_at'] ?? null, $deployment['finished_at'] ?? null);
        $git = trim(substr((string) ($deployment['git_ref'] ?? ''), 0, 7) . ' ' . (strtok((string) ($deployment['git_message'] ?? ''), "\n") ?: ''));

        $lines = [
            Styles::bold('Deployment #' . ($deployment['id'] ?? '')) . " $status",
            'environment: ' . $environment,
            'date:        ' . $this->formatDate($deployment['created_at'] ?? null) . ($duration ? " (took $duration)" : ''),
            'git:         ' . $git,
            'author:      ' . ($deployment['author'] ?? ''),
            'url:         ' . $deployment['url'],
        ];
        if ($deployment['app_url']) {
            $lines[] = 'app url:     ' . $deployment['app_url'];
        }
        if ($deployment['error_message']) {
            $lines[] = 'error:       ' . Styles::red($deployment['error_message']);
        }
        $output->writeln($lines, OutputInterface::OUTPUT_RAW);

        return 0;
    }
}
