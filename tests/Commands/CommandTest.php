<?php declare(strict_types=1);

namespace Bref\Cli\Test\Commands;

use Bref\Cli\Cli\IO;
use Bref\Cli\Commands\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

class CommandTest extends CommandTestCase
{
    private string $configFile;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configFile = sys_get_temp_dir() . '/bref-cli-test-' . bin2hex(random_bytes(4)) . '.yml';
        file_put_contents($this->configFile, "service: shop\nbref:\n    team: acme\n");
    }

    protected function tearDown(): void
    {
        unlink($this->configFile);
        parent::tearDown();
    }

    public function test_asks_for_colors_when_the_output_is_a_terminal(): void
    {
        [$status, $display] = $this->runCommand(decorated: true);

        $this->assertSame(0, $status, $display);
        $this->assertSame(['environmentId' => 12, 'command' => 'about', 'ansi' => true], $this->requestBodies['/api/v1/commands/start']);
        // Printed as is: the terminal renders the colors
        $this->assertStringContainsString("\e[32mLaravel\e[39m", $display);
    }

    public function test_no_colors_when_the_output_is_not_a_terminal(): void
    {
        [$status, $display] = $this->runCommand(decorated: false);

        $this->assertSame(0, $status, $display);
        $this->assertFalse($this->requestBodies['/api/v1/commands/start']['ansi']);
    }

    public function test_the_output_is_printed_as_is(): void
    {
        // Text that looks like Symfony Console tags is not interpreted: it's the output of the application
        $output = 'Hello <info>world</info>, <href=https://example.com>a link</>';

        [$status, $display] = $this->runCommand(decorated: true, responses: [['status' => 'success', 'output' => $output, 'offset' => 10, 'error' => null]]);
        $this->assertSame(0, $status, $display);
        $this->assertStringContainsString($output, $display);
    }

    public function test_the_output_is_printed_while_the_command_runs(): void
    {
        [$status, $display] = $this->runCommand(decorated: false, responses: [
            ['status' => 'started', 'output' => "Migrating\n", 'offset' => 10, 'error' => null],
            ['status' => 'started', 'output' => '', 'offset' => 10, 'error' => null],
            ['status' => 'success', 'output' => "Migrated\n", 'offset' => 19, 'error' => null],
        ]);

        $this->assertSame(0, $status, $display);
        $this->assertStringEndsWith("› Running\nMigrating\nMigrated\n", $display);
        // Each call asks for what has not been printed yet
        $this->assertSame(['/api/v1/commands/5?offset=0', '/api/v1/commands/5?offset=10', '/api/v1/commands/5?offset=10'], array_values(array_filter(
            $this->requests,
            fn (string $request) => str_starts_with($request, '/api/v1/commands/5'),
        )));
    }

    public function test_a_failed_command_shows_why(): void
    {
        [$status, $display] = $this->runCommand(decorated: false, responses: [
            ['status' => 'failed', 'output' => "Migrating\n", 'offset' => 10, 'error' => 'The command exited with code 1'],
        ]);

        $this->assertSame(1, $status, $display);
        $this->assertStringEndsWith("› Running\nMigrating\n\nERROR   The command exited with code 1\n", $display);
    }

    /**
     * @param list<array{status: string, output: string, offset: int, error: string|null}> $responses What Bref Cloud returns for the command, call after call
     * @return array{int, string} The exit code and the output
     */
    private function runCommand(bool $decorated, array $responses = [['status' => 'success', 'output' => "\e[32mLaravel\e[39m 13", 'offset' => 20, 'error' => null]]): array
    {
        $this->requestBodies = [];
        $this->requests = [];
        $command = new Command($this->brefCloud([
            '/api/v1/environments/find' => $this->environment(),
            '/api/v1/commands/start' => ['id' => 5],
            '/api/v1/commands/5' => function () use (&$responses) {
                return $this->json(array_shift($responses));
            },
        ]));
        $input = new ArrayInput(['args' => 'about', '--config' => $this->configFile]);
        $input->setInteractive(false);
        $output = new BufferedOutput(decorated: $decorated);
        // What the application does before running a command
        IO::init($input, $output);

        $status = $command->run($input, $output);
        IO::stop();

        return [$status, $output->fetch()];
    }
}
