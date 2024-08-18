<?php declare(strict_types=1);

namespace Bref\Cli\Commands;

use Bref\Cli\BrefCloudClient;
use Bref\Cli\Cli\IO;
use Bref\Cli\Cli\Styles;
use Bref\Cli\Config;
use JsonException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class Command extends \Symfony\Component\Console\Command\Command
{
    protected function configure(): void
    {
        $this
            ->setName('command')
            ->setDescription('Run a CLI command in the deployed application')
            ->addArgument('args', InputArgument::OPTIONAL, 'The command to run', '')
            ->addOption('app', null, InputOption::VALUE_REQUIRED, 'The app name (if outside of a project directory)')
            ->addOption('env', 'e', InputOption::VALUE_REQUIRED, 'The environment to deploy to', 'dev');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var string $command */
        $command = $input->getArgument('args');
        /** @var string $environment */
        $environment = $input->getOption('env');
        /** @var string|null $appName */
        $appName = $input->getOption('app');
        if (! $appName) {
            $appName = Config::loadConfig()['name'];
        }

        $brefCloud = new BrefCloudClient;
        $result = $brefCloud->startCommand($appName, $environment, $command);

        if ($result['success']) {
            IO::writeln($result['output']);
        } else {
            try {
                $errorDetails = json_decode($result['output'], true, 512, JSON_THROW_ON_ERROR);
                if (is_array($errorDetails) && isset($errorDetails['errorType'], $errorDetails['errorMessage'])) {
                    $message = nl2br($errorDetails['errorMessage']);
                    IO::writeln([
                        '',
                        Styles::red('ERROR'),
                        Styles::gray($errorDetails['errorType']),
                        '',
                        $message,
                    ]);
                    if (isset($errorDetails['stackTrace']) && is_array($errorDetails['stackTrace'])) {
                        IO::verbose($errorDetails['stackTrace']);
                    }
                } else {
                    IO::writeln(Styles::red($result['output']));
                }
            } catch (JsonException) {
                IO::writeln(Styles::red($result['output']));
            }
        }

        return $result['success'] ? 0 : 1;
    }
}