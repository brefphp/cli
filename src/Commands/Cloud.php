<?php declare(strict_types=1);

namespace Bref\Cli\Commands;

use Bref\Cli\BrefCloudClient;
use Bref\Cli\Cli\IO;
use Bref\Cli\Cli\OpenUrl;
use Bref\Cli\Cli\Styles;
use Bref\Cli\Config;
use JsonException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class Cloud extends \Symfony\Component\Console\Command\Command
{
    protected function configure(): void
    {
        $this
            ->setName('cloud')
            ->setDescription('Open the Bref Cloud dashboard')
            ->addOption('config', 'c', InputOption::VALUE_REQUIRED, 'The location of the configuration file to use')
            ->addOption('env', 'e', InputOption::VALUE_REQUIRED, 'The environment', 'dev');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var string|null $configFileName */
        $configFileName = $input->getOption('config');
        /** @var string $environment */
        $environment = $input->getOption('env');

        $config = Config::loadConfig($configFileName, $environment, null);
        $appName = $config['name'];
        $teamSlug = $config['team'];

        $brefCloud = new BrefCloudClient;
        $environment = $brefCloud->getEnvironment($teamSlug, $appName, $environment);

        $url = $brefCloud->url . '/environment/' . $environment['id'];

        IO::writeln([Styles::brefHeader(), '']);
        IO::writeln([
            'Opening the Bref Cloud dashboard for the environment',
            Styles::gray(Styles::underline($url)),
            '',
        ]);

        OpenUrl::open($url);

        return 0;
    }
}