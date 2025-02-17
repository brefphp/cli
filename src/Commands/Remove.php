<?php declare(strict_types=1);

namespace Bref\Cli\Commands;

use Bref\Cli\BrefCloudClient;
use Bref\Cli\Cli\IO;
use Bref\Cli\Cli\Styles;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;
use function Amp\delay;

class Remove extends ApplicationCommand
{
    protected function configure(): void
    {
        $this
            ->setName('remove')
            ->setDescription('Remove an environment of an application');
        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        [
            'appName' => $appName,
            'environmentName' => $environmentName,
            'team' => $team,
        ] = $this->parseStandardOptions($input);

        IO::writeln([
            sprintf("Removing environment %s of application %s", Styles::bold($environmentName), Styles::bold($appName)),
            '',
        ]);

        IO::spin('removing');

        $brefCloud = new BrefCloudClient;
        $environment = $brefCloud->findEnvironment($team, $appName, $environmentName);
        $environmentId = $environment['id'];

        IO::verbose('Triggering asynchronous removal of environment ' . $environmentId);
        $brefCloud->removeEnvironment($environmentId);

        while (true) {
            try {
                $brefCloud->getEnvironment($environmentId);
            } catch (HttpExceptionInterface $e) {
                if ($e->getResponse()->getStatusCode() === 404) {
                    break;
                }
            }

            delay(1);
        }

        IO::spinSuccess('removed');

        return 0;
    }
}
