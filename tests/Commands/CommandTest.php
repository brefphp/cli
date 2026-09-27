<?php declare(strict_types=1);

namespace Bref\Cli\Test\Commands;

use Bref\Cli\Application;
use Bref\Cli\Commands\Command;
use Symfony\Component\Console\Tester\ApplicationTester;

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
        $tester = $this->runCommand(decorated: true);

        $this->assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        $this->assertSame(['environmentId' => 12, 'command' => 'about', 'ansi' => true], $this->requestBodies['/api/v1/commands/start']);
        // Printed as is: the terminal renders the colors
        $this->assertStringContainsString("\e[32mLaravel\e[39m", $tester->getDisplay());
    }

    public function test_no_colors_when_the_output_is_not_a_terminal(): void
    {
        $tester = $this->runCommand(decorated: false);

        $this->assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        $this->assertFalse($this->requestBodies['/api/v1/commands/start']['ansi']);
    }

    private function runCommand(bool $decorated): ApplicationTester
    {
        // Through the application, which sets up the output of the `IO` class
        $application = new Application;
        restore_error_handler();
        $application->setAutoExit(false);
        $application->safeAddCommand(new Command($this->brefCloud([
            '/api/v1/environments/find' => $this->environment(),
            '/api/v1/commands/start' => ['id' => 5],
            '/api/v1/commands/5' => ['status' => 'success', 'output' => "\e[32mLaravel\e[39m 13"],
        ])));
        $tester = new ApplicationTester($application);
        $tester->run(['command' => 'command', 'args' => 'about', '--config' => $this->configFile], ['decorated' => $decorated]);

        return $tester;
    }
}
