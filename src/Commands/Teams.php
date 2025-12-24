<?php declare(strict_types=1);

namespace Bref\Cli\Commands;

use Bref\Cli\BrefCloudClient;
use Bref\Cli\Cli\IO;
use Exception;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class Teams extends Command
{
    protected function configure(): void
    {
        $this
            ->setName('teams')
            ->setDescription('List the Bref Cloud teams you have access to');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $teams = (new BrefCloudClient)->listTeams();

        if (empty($teams)) {
            IO::writeln('You do not have access to any teams.');
            return 0;
        }

        foreach ($teams as $team) {
            IO::writeln(sprintf("%s (%s)", $team['slug'], $team['name']));
        }

        return 0;
    }
}
