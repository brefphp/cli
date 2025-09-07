<?php

namespace Bref\Cli\Commands;

use Bref\Cli\Cli\IO;
use Bref\Cli\Cli\Styles;
use Bref\Cli\Tinker\BrefTinkerShell;
use Psy\Configuration;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class Tinker extends ApplicationCommand
{
    protected function configure(): void
    {
        ini_set('memory_limit', '512M');

        $this
            ->setName('tinker')
            ->setDescription('Run a Tinker shell in the lambda');
        parent::configure();
    }
    
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        IO::writeln([Styles::brefHeader(), '']);
        
        $brefCloudConfig = $this->parseStandardOptions($input);

        // Auto enable verbose to avoid verbose async listener in VerboseModeEnabler which will cause issue when executing multiple commands
        IO::enableVerbose();
        IO::writeln(sprintf(
            "Starting Interactive Shell Session for [%s] in the [%s] environment",
            Styles::green($brefCloudConfig['appName']),
            Styles::red($brefCloudConfig['environmentName']),
        ));
        
        $shellConfig = Configuration::fromInput($input);
        $shellOutput = $shellConfig->getOutput();
        
        $shell = new BrefTinkerShell($shellConfig, $brefCloudConfig);
        $shell->setRawOutput($shellOutput);

        try {
            return $shell->run();
        } catch (\Throwable $e) {
            IO::writeln(Styles::red($e->getMessage()));
            return 1;
        }
    }
}
