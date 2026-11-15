<?php declare(strict_types=1);

namespace Bref\Cli\Commands;

use Bref\Cli\BrefCloudClient;
use Bref\Cli\Cli\IO;
use Bref\Cli\Cli\Styles;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;
use function Amp\delay;

class Remove extends ApplicationCommand
{
    public function __construct(
        private readonly ?BrefCloudClient $brefCloud = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('remove')
            ->setDescription('Remove an environment of an application')
            ->addOption('delete-secrets', null, InputOption::VALUE_NONE, 'Also delete the secrets of the environment (kept by default, so that deploying it again reuses them)');
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

        $brefCloud = $this->brefCloud ?? new BrefCloudClient;
        $environment = $brefCloud->findEnvironment($team, $appName, $environmentName);
        $environmentId = $environment['id'];

        IO::verbose('Triggering asynchronous removal of environment ' . $environmentId);
        $message = $brefCloud->removeEnvironment($environmentId, (bool) $input->getOption('delete-secrets'));

        // Bref Cloud says why the removal takes long, or why it failed
        $shownMessage = null;
        while (true) {
            try {
                $removal = $brefCloud->getEnvironment($environmentId)['removal'] ?? null;
            } catch (HttpExceptionInterface $e) {
                if ($e->getResponse()->getStatusCode() === 404) {
                    break;
                }
                $removal = null;
            }

            // Only AWS cleaning up is left (e.g. network interfaces of functions in a VPC): Bref
            // Cloud finishes the removal, there is nothing for the user to wait for
            if (($removal['status'] ?? null) === 'finishing') {
                IO::spinSuccess('removed');
                IO::writeln(['', Styles::gray($removal['message'] ?? 'Bref Cloud finishes the removal in the background.')]);
                break;
            }
            if (($removal['status'] ?? null) === 'failed') {
                IO::spinError('failed');
                IO::writeln(Styles::bold(Styles::red($removal['message'] ?? 'Unknown error')));
                return 1;
            }
            $progress = $removal['message'] ?? null;
            if ($progress !== null && $progress !== $shownMessage) {
                IO::spinClear();
                IO::writeln([Styles::gray($progress), '']);
                IO::spin('removing');
                $shownMessage = $progress;
            }

            delay(3);
        }

        IO::spinSuccess('removed');

        if ($message) {
            IO::writeln(['', $message]);
        }

        return 0;
    }
}
