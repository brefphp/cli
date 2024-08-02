<?php declare(strict_types=1);

namespace Bref\Cli\Commands;

use Bref\Cli\Helpers\Styles;
use Laravel\Prompts\Spinner;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class Deploy extends Command
{
    protected function configure(): void
    {
        $this
            ->setName('deploy')
            ->setDescription('Deploy the application');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln([Styles::brefHeader(), '']);

        $output->writeln('Deploying my-app to environment production');

        $spinner = new Spinner('deploying');
        $spinner->spin(function () use ($spinner) {
            for ($i = 0; $i < 5; $i++) {
                sleep(1);
                $spinner->message = 'deploying ' . Styles::gray("› {$i}s");
            }
        });

        $output->writeln('Deployment complete');

        sleep(2);

        $output->writeln('Shutting off');

        sleep(2);

        return 0;
    }
}