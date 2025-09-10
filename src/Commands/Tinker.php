<?php

namespace Bref\Cli\Commands;

use Bref\Cli\BrefCloudClient;
use Bref\Cli\Cli\IO;
use Bref\Cli\Cli\Styles;
use Bref\Cli\Tinker\BrefTinkerShell;
use Psy\Configuration;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

class Tinker extends ApplicationCommand
{
    protected function configure(): void
    {
        ini_set('memory_limit', '512M');

        $this
            ->setName('tinker')
            ->setDescription('Run Laravel Tinker in AWS Lambda');
        parent::configure();
    }
    
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$this->isLaravelApplication()) {
            IO::writeln(Styles::red('This command can only be run in a Laravel application.'));
            return 1;
        }
        
        IO::writeln([Styles::brefHeader(), '']);
        
        [
            'appName' => $appName,
            'environmentName' => $environmentName,
            'team' => $team,
        ] = $this->parseStandardOptions($input);

        // Auto enable verbose to avoid verbose async listener in VerboseModeEnabler which will cause issue when executing multiple commands
        IO::enableVerbose();
        IO::writeln(sprintf(
            "Starting interactive shell for [%s] in the [%s] environment",
            Styles::bold($appName),
            Styles::bold($environmentName),
        ));

        $environment = (new BrefCloudClient)->findEnvironment($team, $appName, $environmentName);
        $environmentId = $environment['id'];
        
        $shellConfig = Configuration::fromInput($input);

        $shell = new BrefTinkerShell($shellConfig, $environmentId, $shellConfig->getOutput());

        try {
            return $shell->run();
        } catch (Throwable $e) {
            IO::writeln(Styles::red($e->getMessage()));
            return 1;
        }
    }
    
    protected function isLaravelApplication(): bool
    {
        $composerContent = file_get_contents('composer.json');
        if ($composerContent === false) {
            return false;
        }

        /** @var array<string, mixed> $composerJson */
        $composerJson = json_decode($composerContent, true, 512, JSON_THROW_ON_ERROR);
        $requires = (array) ($composerJson['require'] ?? []);
        $requiresDev = (array) ($composerJson['require-dev'] ?? []);
        return isset($requires['laravel/framework']) || isset($requiresDev['laravel/framework']);
    }
}
