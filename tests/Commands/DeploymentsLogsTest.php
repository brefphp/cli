<?php declare(strict_types=1);

namespace Bref\Cli\Test\Commands;

use Bref\Cli\Commands\DeploymentsLogs;
use Symfony\Component\Console\Tester\CommandTester;

class DeploymentsLogsTest extends CommandTestCase
{
    public function test_json_never_has_colors(): void
    {
        $tester = new CommandTester(new DeploymentsLogs($this->brefCloud([
            '/api/v1/deployments/25' => [
                'status' => 'success',
                'message' => 'deployed',
                'error_message' => null,
                'url' => 'https://bref.cloud/d/25',
                'app_url' => null,
                'logs' => [['line' => "\e[32m✔\e[39m Packaged", 'timestamp' => 1790157600]],
            ],
        ])));

        // A human in a terminal
        $tester->execute(['id' => '25', '--json' => true], ['decorated' => true]);

        $this->assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        $this->assertSame(
            ['id' => 25, 'status' => 'success', 'error_message' => null, 'logs' => [['line' => '✔ Packaged', 'timestamp' => 1790157600]]],
            json_decode($tester->getDisplay(), true),
        );
    }

    public function test_agents_get_the_logs_as_text_without_colors(): void
    {
        $this->runByAnAgent();
        $tester = new CommandTester(new DeploymentsLogs($this->brefCloud([
            '/api/v1/deployments/25' => [
                'status' => 'failed',
                'message' => 'failed',
                'error_message' => 'The CloudFormation stack failed to update',
                'url' => 'https://bref.cloud/d/25',
                'app_url' => null,
                'logs' => [
                    ['line' => "\e[32m✔\e[39m Packaged", 'timestamp' => 1790157600],
                    ['line' => 'UPDATE_FAILED AWS::Lambda::Function', 'timestamp' => 1790157660],
                ],
            ],
        ])));

        $tester->execute(['id' => '25'], ['capture_stderr_separately' => true, 'decorated' => true]);

        $this->assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        $this->assertSame("✔ Packaged\nUPDATE_FAILED AWS::Lambda::Function\n", $tester->getDisplay());
        $this->assertSame("Deployment #25: failed (The CloudFormation stack failed to update)\n", $tester->getErrorOutput());
    }
}
