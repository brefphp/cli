<?php declare(strict_types=1);

namespace Bref\Cli\Test\Commands;

use Bref\Cli\BrefCloudClient;
use Bref\Cli\Cli\IO;
use Bref\Cli\Commands\SecretCreate;
use Exception;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

class SecretCreateTest extends CommandTestCase
{
    private const TEAMS = [['id' => 7, 'name' => 'Acme', 'slug' => 'acme']];
    private const AWS_ACCOUNTS = [
        ['id' => 1, 'name' => 'production', 'role_arn' => 'arn:aws:iam::123456789012:role/bref-cloud', 'team_id' => 7],
        ['id' => 2, 'name' => 'other-team', 'role_arn' => 'arn:aws:iam::210987654321:role/bref-cloud', 'team_id' => 8],
    ];

    public function test_a_deployed_environment_uses_its_aws_account_and_region(): void
    {
        $this->createSecret(['--app' => 'shop', '--team' => 'acme', '--env' => 'prod'], [
            '/api/v1/environments/find' => $this->environment(),
        ]);

        $this->assertSecretCreatedIn(1, 'us-east-1');
    }

    public function test_options_pointing_elsewhere_than_the_deployed_environment_are_ignored_with_a_warning(): void
    {
        $output = $this->createSecret(['--app' => 'shop', '--team' => 'acme', '--env' => 'prod', '--aws-account' => 'staging', '--region' => 'eu-west-3'], [
            '/api/v1/environments/find' => $this->environment(),
            '/api/v1/aws-accounts' => self::AWS_ACCOUNTS,
        ]);

        $this->assertSecretCreatedIn(1, 'us-east-1');
        $this->assertStringContainsString('The environment is deployed in us-east-1: the --region option is ignored, the secret is created in us-east-1', $output);
        $this->assertStringContainsString("The environment is deployed in the AWS account 'production': the --aws-account option is ignored, the secret is created in that account", $output);
    }

    public function test_options_matching_the_deployed_environment_do_not_warn(): void
    {
        $output = $this->createSecret(['--app' => 'shop', '--team' => 'acme', '--env' => 'prod', '--aws-account' => 'production', '--region' => 'us-east-1'], [
            '/api/v1/environments/find' => $this->environment(),
            '/api/v1/aws-accounts' => self::AWS_ACCOUNTS,
        ]);

        $this->assertSecretCreatedIn(1, 'us-east-1');
        $this->assertStringNotContainsString('ignored', $output);
    }

    public function test_an_environment_not_deployed_yet_uses_the_options(): void
    {
        $this->createSecret(['--app' => 'shop', '--team' => 'acme', '--env' => 'prod', '--aws-account' => 'production', '--region' => 'eu-west-3'], [
            '/api/v1/environments/find' => $this->json(['message' => 'Not found'], 404),
            '/api/v1/aws-accounts' => [...self::AWS_ACCOUNTS, ['id' => 3, 'name' => 'staging', 'role_arn' => 'arn:aws:iam::111111111111:role/bref-cloud', 'team_id' => 7]],
        ]);

        $this->assertSecretCreatedIn(1, 'eu-west-3');
    }

    public function test_an_environment_not_deployed_yet_defaults_to_the_only_aws_account_and_the_region_of_serverless_yml(): void
    {
        $configFile = sys_get_temp_dir() . '/bref-cli-test-' . bin2hex(random_bytes(4)) . '.yml';
        file_put_contents($configFile, "service: shop\nbref:\n    team: acme\nprovider:\n    region: eu-west-3\n");
        try {
            $this->createSecret(['--config' => $configFile, '--env' => 'prod'], [
                '/api/v1/environments/find' => $this->json(['message' => 'Not found'], 404),
                '/api/v1/aws-accounts' => self::AWS_ACCOUNTS,
            ]);
        } finally {
            unlink($configFile);
        }

        $this->assertSecretCreatedIn(1, 'eu-west-3');
    }

    public function test_an_environment_not_deployed_yet_without_region_asks_for_the_option(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('The environment is not deployed yet: set the AWS region with the --region option');

        $this->createSecret(['--app' => 'shop', '--team' => 'acme', '--env' => 'prod'], [
            '/api/v1/environments/find' => $this->json(['message' => 'Not found'], 404),
            '/api/v1/aws-accounts' => self::AWS_ACCOUNTS,
        ]);
    }

    public function test_an_environment_not_deployed_yet_with_several_aws_accounts_asks_for_the_option(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('The environment is not deployed yet and the team has several AWS accounts: set the AWS account to use with the --aws-account option (production, staging)');

        $this->createSecret(['--app' => 'shop', '--team' => 'acme', '--env' => 'prod', '--region' => 'eu-west-3'], [
            '/api/v1/environments/find' => $this->json(['message' => 'Not found'], 404),
            '/api/v1/aws-accounts' => [...self::AWS_ACCOUNTS, ['id' => 3, 'name' => 'staging', 'role_arn' => 'arn:aws:iam::111111111111:role/bref-cloud', 'team_id' => 7]],
        ]);
    }

    /**
     * @param array<string, mixed> $options
     * @param array<string, mixed> $routes
     */
    private function createSecret(array $options, array $routes): string
    {
        $input = new ArrayInput(['name' => 'APP_KEY', 'value' => 'secret', ...$options]);
        $input->setInteractive(false);
        $output = new BufferedOutput;
        IO::init($input, $output);

        $command = new SecretCreate($this->brefCloud([
            '/api/v1/teams' => self::TEAMS,
            '/api/v1/secrets' => $this->json(['secret_path' => '/shop/prod/APP_KEY'], 201),
            ...$routes,
        ]));
        $status = $command->run($input, $output);
        $display = $output->fetch();
        $this->assertSame(0, $status, $display);

        return $display;
    }

    private function assertSecretCreatedIn(int $awsAccountId, string $region): void
    {
        $this->assertSame([
            'team_id' => 7,
            'app_name' => 'shop',
            'environment_name' => 'prod',
            'name' => 'APP_KEY',
            'value' => 'secret',
            'aws_account_id' => $awsAccountId,
            'region' => $region,
        ], $this->requestBodies['/api/v1/secrets']);
    }
}
