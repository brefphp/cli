<?php declare(strict_types=1);

namespace Bref\Cli\Test\Commands;

use Bref\Cli\Commands\DeploymentsShow;
use Symfony\Component\Console\Tester\CommandTester;

class DeploymentsShowTest extends CommandTestCase
{
    private const DEPLOYMENT = DeploymentsTest::DEPLOYMENT + [
        'app_url' => 'https://shop.example.com',
        'environment' => ['id' => 12, 'name' => 'prod'],
        'app' => ['id' => 3, 'name' => 'shop'],
        'logs' => [['line' => 'Deploying', 'timestamp' => 1790157600]],
    ];

    public function test_shows_the_latest_deployment_of_the_environment(): void
    {
        $tester = new CommandTester(new DeploymentsShow($this->brefCloud([
            '/api/v1/environments/find' => $this->environment(),
            '/api/v1/environments/12/deployments' => [DeploymentsTest::DEPLOYMENT],
            '/api/v1/deployments/25' => self::DEPLOYMENT,
        ])));

        $tester->execute(['--app' => 'shop', '--team' => 'acme', '--json' => true]);

        $this->assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        $this->assertSame('/api/v1/environments/12/deployments?limit=1', $this->requests[1]);
        $expected = self::DEPLOYMENT;
        // `deployments:logs` shows them
        unset($expected['logs']);
        $this->assertSame($expected, json_decode($tester->getDisplay(), true));
    }

    /**
     * An ID is enough: no config file, app or team needed.
     */
    public function test_shows_a_deployment_by_id(): void
    {
        $tester = new CommandTester(new DeploymentsShow($this->brefCloud([
            '/api/v1/deployments/25' => self::DEPLOYMENT,
        ])));

        $tester->execute(['id' => '25'], ['decorated' => true]);

        $this->assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        $this->assertSame(['/api/v1/deployments/25'], $this->requests);
        $display = (string) preg_replace('/\e\[[0-9;]*m/', '', $tester->getDisplay());
        $this->assertStringContainsString("Deployment #25 failed\nenvironment: shop / prod\ndate:        2026-09-23 10:00 UTC (took 1m 20s)\ngit:         a1b2c3d Fix the checkout\n", $display);
        $this->assertStringContainsString('error:       The CloudFormation stack failed to update', $display);
    }

    public function test_an_environment_without_deployments(): void
    {
        $tester = new CommandTester(new DeploymentsShow($this->brefCloud([
            '/api/v1/environments/find' => $this->environment(),
            '/api/v1/environments/12/deployments' => [],
        ])));

        $tester->execute(['--app' => 'shop', '--team' => 'acme', '--json' => true]);

        $this->assertSame(['error' => ['message' => 'This environment has not been deployed yet.']], json_decode($tester->getDisplay(), true));
    }
}
