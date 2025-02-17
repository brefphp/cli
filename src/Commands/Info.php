<?php declare(strict_types=1);

namespace Bref\Cli\Commands;

use Bref\Cli\BrefCloudClient;
use Bref\Cli\Cli\IO;
use Bref\Cli\Cli\Styles;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class Info extends ApplicationCommand
{
    protected function configure(): void
    {
        $this
            ->setName('info')
            ->setDescription('Show information about the application');
        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        [
            'appName' => $appName,
            'environmentName' => $environmentName,
            'config' => $config,
        ] = $this->parseStandardOptions($input);

        IO::writeln([
            sprintf("%s %s / %s", Styles::brefLogo(), Styles::bold($appName), Styles::bold($environmentName)),
            '',
        ]);

        $brefCloud = new BrefCloudClient;
        $environment = $brefCloud->findEnvironment($config['team'], $appName, $environmentName);
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
