<?php declare(strict_types=1);

namespace Bref\Cli\Commands;

use Bref\Cli\BrefCloudClient;
use Bref\Cli\Config;
use Bref\Cli\Helpers\BrefSpinner;
use Bref\Cli\Helpers\Styles;
use JsonException;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Helper\Helper;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use function Termwind\render;

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
        $command = $input->getArgument('args');
        $environment = $input->getOption('env');
        $appName = $input->getOption('app');
        if (! $appName) {
            $appName = Config::loadConfig()['name'];
        }

        $brefCloud = new BrefCloudClient;
        $result = $brefCloud->startCommand($appName, $environment, $command);

        if ($result['success']) {
            $output->writeln($result['output']);
        } else {
            try {
                $errorDetails = json_decode($result['output'], true, 512, JSON_THROW_ON_ERROR);
                if (isset($errorDetails['errorType'], $errorDetails['errorMessage'])) {
                    $message = nl2br($errorDetails['errorMessage']);
                    render(<<<HTML
                        <div>
                            <div class="my-1">
                                <span class="px-1 bg-red-500">ERROR</span>
                                <span class="px-1 text-gray-500">{$errorDetails['errorType']}</span>
                            </div>
                            <div>$message</div>
                        </div>
                    HTML);
                    if (isset($errorDetails['stackTrace']) && is_array($errorDetails['stackTrace'])) {
                        // TODO log trace to verbose
                        $trace = implode("<br>", $errorDetails['stackTrace']);
                    }
                } else {
                    $output->writeln(Styles::red($result['output']));
                }
            } catch (JsonException) {
                $output->writeln(Styles::red($result['output']));
            }
        }

        return $result['success'] ? 0 : 1;
    }
}