<?php declare(strict_types=1);

namespace Bref\Cli\Commands;

use Bref\Cli\Cli\OutputMode;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class DeploymentsLogs extends EnvironmentDataCommand
{
    protected function configure(): void
    {
        $this
            ->setName('deployments:logs')
            ->setDescription('Show the logs of a deployment')
            ->addArgument('id', InputArgument::OPTIONAL, 'The deployment ID (default: the latest deployment of the environment)')
            ->setHelp(<<<'HELP'
                Shows the output of a deployment, for example to find why it failed.

                Examples:

                  <info>bref deployments:logs --env=prod</info>        the latest deployment of the environment
                  <info>bref deployments:logs 42</info>                works outside the project directory
                HELP);

        parent::configure();
    }

    protected function show(InputInterface $input, OutputInterface $output): int
    {
        $id = $this->deploymentId($input);
        $deployment = $this->brefCloud()->getDeployment($id);
        $json = (bool) $input->getOption('json');
        // Colors are only useful in a terminal, and never in JSON
        $keepColors = OutputMode::isHuman($output) && ! $json;
        $logs = array_map(fn(array $log) => [
            'line' => $keepColors ? $log['line'] : $this->stripAnsi($log['line']),
            'timestamp' => $log['timestamp'],
        ], $deployment['logs']);

        if ($json) {
            $this->writeJson($output, [
                'id' => $id,
                'status' => $deployment['status'],
                'error_message' => $deployment['error_message'],
                'logs' => $logs,
            ]);
            return 0;
        }

        foreach ($logs as $log) {
            $output->writeln($log['line'], OutputInterface::OUTPUT_RAW);
        }
        $summary = "Deployment #$id: {$deployment['message']}";
        if ($deployment['error_message']) {
            $summary .= " ({$deployment['error_message']})";
        }
        $this->stderr($output)->writeln($summary, OutputInterface::OUTPUT_RAW);

        return 0;
    }

    private function stripAnsi(string $text): string
    {
        return (string) preg_replace('/\e\[[0-9;?]*[A-Za-z]/', '', $text);
    }
}
