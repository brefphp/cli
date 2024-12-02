<?php declare(strict_types=1);

namespace Bref\Cli\Commands;

use Amp\ByteStream\BufferException;
use Amp\Process\Process;
use Amp\Process\ProcessException;
use Bref\Cli\BrefCloudClient;
use Bref\Cli\Cli\IO;
use Bref\Cli\Cli\Styles;
use Bref\Cli\Components\ServerlessFramework;
use Bref\Cli\Config;
use Exception;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use ZipArchive;
use function Amp\ByteStream\buffer;
use function Amp\delay;

class Info extends Command
{
    protected function configure(): void
    {
        $this
            ->setName('info')
            ->setDescription('Show information about the application')
            ->addOption('env', 'e', InputOption::VALUE_REQUIRED, 'The environment', 'dev')
            ->addOption('config', 'c', InputOption::VALUE_REQUIRED, 'The location of the configuration file to use')
            ->addOption('team', null, InputOption::VALUE_REQUIRED, 'Override the team');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var string $environment */
        $environment = $input->getOption('env');
        /** @var string|null $configFileName */
        $configFileName = $input->getOption('config');
        $config = Config::loadConfig($configFileName, $environment, $input->getOption('team'));
        $appName = $config['name'];
        IO::writeln([
            sprintf("%s %s / %s", Styles::brefLogo(), Styles::bold($appName), Styles::bold($environment)),
            '',
        ]);

        $brefCloud = new BrefCloudClient;
        $environment = $brefCloud->getEnvironment($config['team'], $appName, $environment);
        $environmentLink = $brefCloud->url . '/environment/' . $environment['id'];
        IO::writeln([
            "<href=$environmentLink>" . Styles::gray($environmentLink) . '</>',
            '',
            'region: ' . ($environment['region'] ?: 'not deployed'),
        ]);

        if ($environment['url']) {
            IO::writeln('url: ' . "<href={$environment['url']}>" . $environment['url'] . '</>');
        }

        return 0;
    }
}
