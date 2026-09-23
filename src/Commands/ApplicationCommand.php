<?php declare(strict_types=1);

namespace Bref\Cli\Commands;

use Bref\Cli\Config;
use Exception;
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
     *     configFileName: string|null,
     * }
     */
    protected function parseStandardOptions(InputInterface $input): array
    {
        /** @var string $environment */
        $environment = $input->getOption('env');
        /** @var string|null $configFileName */
        $configFileName = $input->getOption('config');
        /** @var string|null $overrideTeam */
        $overrideTeam = $input->getOption('team');
        $config = Config::loadConfig($configFileName, $environment, $overrideTeam);

        return [
            'appName' => $config['name'],
            'config' => $config,
            'environmentName' => $environment,
            'team' => $config['team'],
            ...['configFileName' => $configFileName],
        ];
    }

    /**
     * For the commands that only need to identify the environment, and that have an `--app` option.
     *
     * With `--app` and `--team`, no config file is needed: the command works outside the project directory.
     *
     * @return array{appName: string, environmentName: string, team: string}
     */
    protected function parseEnvironmentOptions(InputInterface $input): array
    {
        /** @var string $environment */
        $environment = $input->getOption('env');
        $app = $input->getOption('app');
        $app = is_string($app) && $app !== '' ? $app : null;
        $team = $input->getOption('team');
        $team = is_string($team) && $team !== '' ? $team : null;

        if ($app && $team) {
            return ['appName' => $app, 'environmentName' => $environment, 'team' => $team];
        }

        if (! $input->getOption('config') && ! is_file('bref.php') && ! is_file('serverless.yml')) {
            throw new Exception('No "bref.php" or "serverless.yml" file in the current directory: run the command in the project directory, or set the application with the --app and --team options.');
        }
        ['appName' => $appName, 'team' => $configTeam] = $this->parseStandardOptions($input);

        return ['appName' => $app ?? $appName, 'environmentName' => $environment, 'team' => $configTeam];
    }
}
