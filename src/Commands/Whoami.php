<?php declare(strict_types=1);

namespace Bref\Cli\Commands;

use Bref\Cli\BrefCloudClient;
use Bref\Cli\Cli\IO;
use Exception;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class Whoami extends Command
{
    protected function configure(): void
    {
        $this
            ->setName('whoami')
            ->setDescription('Show the currently logged in user');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        IO::init($input, $output);

        try {
            $brefCloud = new BrefCloudClient();
            $user = $brefCloud->getUserInfo();
        } catch (Exception) {
            IO::writeln('Not logged in. Run "bref login" to authenticate.');
            return 1;
        }

        IO::writeln("Logged in to Bref Cloud as {$user['name']} ({$user['email']})");
        return 0;
    }
}
