<?php declare(strict_types=1);

namespace Bref\Cli\Commands;

use Bref\Cli\BrefCloudClient;
use Bref\Cli\Cli\IO;
use Bref\Cli\Cli\Styles;
use JsonException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use function Amp\delay;

class Command extends ApplicationCommand
{
    public function __construct(
        private readonly ?BrefCloudClient $brefCloud = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('command')
            ->setDescription('Run a CLI command in the deployed application')
            ->addArgument('args', InputArgument::OPTIONAL, 'The command to run', '');
        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        [
            'appName' => $appName,
            'environmentName' => $environmentName,
            'team' => $team,
        ] = $this->parseStandardOptions($input);

        /** @var string $command */
        $command = $input->getArgument('args');

        IO::spin('starting command');

        $brefCloud = $this->brefCloud ?? new BrefCloudClient;
        $environment = $brefCloud->findEnvironment($team, $appName, $environmentName);
        // Colors when the output is a terminal that shows them, the command's output is printed as is
        $id = $brefCloud->startCommand($environment['id'], $command, $output->isDecorated());

        IO::spin('running');

        $offset = 0;
        while (true) {
            $invocation = $brefCloud->getCommandOutput($id, $offset);
            $offset = $invocation['offset'];

            if ($invocation['output'] !== '') {
                // The output shows that the command runs
                IO::spinClear();
                // As is: the output of the command is not formatted for Symfony Console.
                // Complete lines, and writeRaw() ends each with a line break.
                $output = $invocation['output'];
                IO::writeRaw(str_ends_with($output, "\n") ? substr($output, 0, -1) : $output);
            }

            if ($invocation['status'] === 'success') {
                IO::spinClear();
                return 0;
            }

            if ($invocation['status'] === 'failed') {
                IO::spinClear();
                IO::writeln([
                    '',
                    Styles::bold(Styles::red('ERROR')) . '   ' . Styles::gray($invocation['error'] ?? 'The command failed'),
                ]);
                return 1;
            }

            delay(1);
        }
    }
}
