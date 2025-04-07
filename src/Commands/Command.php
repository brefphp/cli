<?php declare(strict_types=1);

namespace Bref\Cli\Commands;

use Bref\Cli\BrefCloudClient;
use Bref\Cli\Cli\IO;
use Bref\Cli\Cli\Styles;
use JsonException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use function Amp\delay;

class Command extends ApplicationCommand
{
    protected function configure(): void
    {
        $this
            ->setName('command')
            ->setDescription('Run a CLI command in the deployed application')
            ->addArgument('args', InputArgument::OPTIONAL, 'The command to run', '');
        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        [
            'appName' => $appName,
            'environmentName' => $environmentName,
            'team' => $team,
        ] = $this->parseStandardOptions($input);

        /** @var string $command */
        $command = $input->getArgument('args');

        IO::spin('starting command');

        $brefCloud = new BrefCloudClient;
        $environment = $brefCloud->findEnvironment($team, $appName, $environmentName);
        $id = $brefCloud->startCommand($environment['id'], $command);

        IO::spin('running');

        // Timeout after 2 minutes and 10 seconds
        $timeout = 130;
        $startTime = time();

        while (true) {
            $invocation = $brefCloud->getCommand($id);

            if ($invocation['status'] === 'success') {
                IO::spinClear();
                IO::writeln($invocation['output']);
                return 0;
            }

            if ($invocation['status'] === 'failed') {
                IO::spinClear();
                $this->writeErrorDetails($invocation['output']);
                return 1;
            }

            if ((time() - $startTime) > $timeout) {
                IO::spinClear();
                IO::writeln(Styles::red('Timed out'));
                IO::writeln(Styles::gray('The execution timed out after 2 minutes, the command might still be running'));
                return 1;
            }

            delay(1);
        }
    }

    private function writeErrorDetails(string $output): void
    {
        try {
            $errorDetails = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($errorDetails)
                || ! isset($errorDetails['errorType'], $errorDetails['errorMessage'])
                || ! is_string($errorDetails['errorType'])
                || ! is_string($errorDetails['errorMessage'])) {
                IO::writeln(Styles::red($output));
                return;
            }

            $errorType = $errorDetails['errorType'];
            if ($errorType === 'Bref\ConsoleRuntime\CommandFailed') {
                // No need to show this class, it's noise
                $errorType = '';
            }

            IO::writeln([
                '',
                Styles::bold(Styles::red('ERROR')) . '   ' . Styles::gray($errorType),
                '',
                $errorDetails['errorMessage'],
            ]);
            if (isset($errorDetails['stackTrace']) && is_array($errorDetails['stackTrace'])) {
                IO::verbose($errorDetails['stackTrace']);
            }
        } catch (JsonException) {
            IO::writeln(Styles::red($output));
        }
    }
}