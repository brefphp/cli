<?php declare(strict_types=1);

namespace Bref\Cli\Commands;

use Bref\Cli\Config;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

class ApplicationCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addOption('env', 'e', InputOption::VALUE_REQUIRED, 'The environment', 'dev')
            ->addOption('config', 'c', InputOption::VALUE_REQUIRED, 'The location of the configuration file to use')
            ->addOption('team', null, InputOption::VALUE_REQUIRED, 'Override the team');
    }

    /**
     * @return array{
     *     appName: string,
     *     environmentName: string,
     *     team: string,
     *     config: array{name: string, team: string, type: string},
     * }
     */
    protected function parseStandardOptions(InputInterface $input): array
    {
        /** @var string $environment */
        $environment = $input->getOption('env');
        /** @var string|null $configFileName */
        $configFileName = $input->getOption('config');
        $config = Config::loadConfig($configFileName, $environment, $input->getOption('team'));

        return [
            'appName' => $config['name'],
            'config' => $config,
            'environmentName' => $environment,
            'team' => $config['team'],
        ];
    }
}
