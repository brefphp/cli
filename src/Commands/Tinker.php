<?php

namespace Bref\Cli\Commands;

use Bref\Cli\Tinker\BrefTinkerShell;
use Psy\Configuration;
use Psy\Shell;
use Bref\Cli\Cli\IO;
use Bref\Cli\Cli\Styles;
use Bref\Cli\BrefCloudClient;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\StringInput;
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
        
        [
            'appName' => $appName,
            'environmentName' => $environmentName,
            'team' => $team,
        ] = $this->parseStandardOptions($input);

        // Auto enable verbose to avoid verbose async listener in VerboseModeEnabler which will causes issue when executing multiple commands
        IO::enableVerbose();
        
        $config = Configuration::fromInput($input);
        $shellOutput = $config->getOutput();
        $shellOutput->writeln(sprintf("Starting Interactive Shell Session for <string>[%s]</string> in the <string>[%s]</string> environment", Styles::green($appName), Styles::red($environmentName)));
        
        $shell = new BrefTinkerShell($config, str_replace("tinker", "command", (string) $input));
        $shell->setRawOutput($shellOutput);

        return $shell->run();
    }
}
