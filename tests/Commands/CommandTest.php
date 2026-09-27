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

        [$status, $display] = $this->runCommand(decorated: true, command: ['status' => 'success', 'output' => $output]);
        $this->assertSame(0, $status, $display);
        $this->assertStringContainsString($output, $display);

        [$status, $display] = $this->runCommand(decorated: false, command: [
            'status' => 'failed',
            'output' => json_encode(['errorType' => 'Bref\ConsoleRuntime\CommandFailed', 'errorMessage' => $output]),
        ]);
        $this->assertSame(1, $status, $display);
        $this->assertStringContainsString($output, $display);
    }

    /**
     * @param array{status: string, output: string} $command What Bref Cloud returns for the command
     * @return array{int, string} The exit code and the output
     */
    private function runCommand(bool $decorated, array $command = ['status' => 'success', 'output' => "\e[32mLaravel\e[39m 13"]): array
    {
        $this->requestBodies = [];
        $command = new Command($this->brefCloud([
            '/api/v1/environments/find' => $this->environment(),
            '/api/v1/commands/start' => ['id' => 5],
            '/api/v1/commands/5' => $command,
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
