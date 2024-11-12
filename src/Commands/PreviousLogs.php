<?php declare(strict_types=1);

namespace Bref\Cli\Commands;

use Bref\Cli\BrefCloudClient;
use Bref\Cli\Cli\IO;
use Bref\Cli\Cli\Styles;
use Bref\Cli\Token;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;

class PreviousLogs extends Command
{
    protected function configure(): void
    {
        $this
            ->setName('previous-logs')
            ->setDescription('Print the verbose logs of the previous command');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        IO::writeln(IO::previousVerboseLogs());

        return 0;
    }
}
