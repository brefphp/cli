<?php declare(strict_types=1);

namespace Bref\Cli\Test\Commands;

use Bref\Cli\Commands\Deployments;
use Symfony\Component\Console\Tester\CommandTester;

class DeploymentsTest extends CommandTestCase
{
    public const DEPLOYMENT = [
        'id' => 25,
        'status' => 'failed',
        'message' => 'failed',
        'error_message' => 'The CloudFormation stack failed to update',
        'git_ref' => 'a1b2c3d4e5f6',
        'git_message' => "Fix the checkout\n\nLonger description",
        'author' => 'Alice',
        'created_at' => '2026-09-23T10:00:00Z',
        'finished_at' => '2026-09-23T10:01:20Z',
        'url' => 'https://bref.cloud/d/25',
    ];

    public function test_agents_get_json(): void
    {
        $this->runByAnAgent();
        $tester = new CommandTester(new Deployments($this->brefCloud([
            '/api/v1/environments/find' => $this->environment(),
            '/api/v1/environments/12/deployments' => [self::DEPLOYMENT],
        ])));

        $tester->execute(['--app' => 'shop', '--team' => 'acme', '--limit' => '5'], ['decorated' => true]);

        $this->assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        $this->assertSame([self::DEPLOYMENT], json_decode($tester->getDisplay(), true));
        $this->assertSame('/api/v1/environments/12/deployments?limit=5', $this->requests[1]);
    }

    public function test_humans_get_a_table(): void
    {
        $tester = new CommandTester(new Deployments($this->brefCloud([
            '/api/v1/environments/find' => $this->environment(),
            // Not a console style
            '/api/v1/environments/12/deployments' => [['git_message' => 'Fix the <error> checkout'] + self::DEPLOYMENT],
        ])));

        $tester->execute(['--app' => 'shop', '--team' => 'acme'], ['decorated' => true]);

        $this->assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        $this->assertSame(
            '#25 failed 2026-09-23 10:00 UTC 1m 20s a1b2c3d Fix the <error> checkout Alice',
            trim((string) preg_replace(['/\e\[[0-9;]*m/', '/ +/'], ['', ' '], $tester->getDisplay())),
        );
    }
}
