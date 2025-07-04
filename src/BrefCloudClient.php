<?php declare(strict_types=1);

namespace Bref\Cli;

use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class BrefCloudClient
{
    private const PRODUCTION_URL = 'https://bref.cloud';
    private const STAGING_URL = 'https://staging.bref.cloud';
    private const LOCAL_URL = 'http://localhost:8000';

    public readonly string $url;
    private HttpClientInterface $client;

    public function __construct(?string $token = null)
    {
        $this->url = self::getUrl();
        if ($token === null) {
            $token = Token::getToken($this->url);
        }

        $this->client = HttpClient::createForBaseUri($this->url, [
            'timeout' => 15,
            'auth_bearer' => $token,
            'headers' => [
                'User-Agent' => 'Bref CLI',
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
        ]);
    }

    public static function getUrl(): string
    {
        $env = $_SERVER['BREF_ENV'] ?? 'prod';
        return match($env) {
            'staging' => self::STAGING_URL,
            'local' => self::LOCAL_URL,
            default => self::PRODUCTION_URL,
        };
    }

    /**
     * @return array{id: int, name: string}
     * @throws HttpExceptionInterface
     * @throws ExceptionInterface
     */
    public function getUserInfo(): array
    {
        return $this->client->request('GET', '/api/v1/user')->toArray();
    }

    /**
     * @param array<string, string> $config
     * @return array{
     *     deploymentId: int,
     *     message: string,
     *     url: string,
     *     credentials?: array{
     *         accessKeyId: string,
     *         secretAccessKey: string,
     *         sessionToken: string,
     *     },
     *     packageUrls?: array<string, string>,
     * }
     *
     * @throws HttpExceptionInterface
     * @throws ExceptionInterface
     */
    public function createDeployment(string $environment, array $config, string|null $gitRef, string $gitMessage, ?string $awsAccountName = null): array
    {
        $body = [
            'environment' => $environment,
            'config' => $config,
            'git_ref' => $gitRef,
            'git_message' => $gitMessage,
        ];
        if ($awsAccountName) {
            $body['aws_account_name'] = $awsAccountName;
        }
        return $this->client->request('POST', '/api/v1/deployments', [
            'json' => $body,
        ])->toArray();
    }

    public function startDeployment(int $deploymentId): void
    {
        $this->client->request('POST', "/api/v1/deployments/$deploymentId/start");
    }

    /**
     * @return array{
     *     deploymentId: int,
     *     status: string,
     *     message: string,
     *     error_message: string|null,
     *     url: string,
     *     app_url: string|null,
     *     logs: list<array{line: string, timestamp: int}>,
     *     outputs?: array<string, string>,
     * }
     *
     * @throws HttpExceptionInterface
     * @throws ExceptionInterface
     */
    public function getDeployment(int $deploymentId): array
    {
        return $this->client->request('GET', "/api/v1/deployments/$deploymentId")->toArray();
    }

    public function pushDeploymentLogs(int $deploymentId, string $newLogs): void
    {
        $this->client->request('POST', "/api/v1/deployments/$deploymentId/logs", [
            'json' => [
                'logs' => $newLogs,
            ],
        ]);
    }

    /**
     * @param array<string, string> $outputs
     */
    public function markDeploymentFinished(
        int $deploymentId,
        bool $success,
        ?string $errorMessage,
        string $logs,
        ?string $region = null,
        ?string $stackName = null,
        ?array $outputs = null,
    ): void
    {
        $body = [
            'success' => $success,
            'errorMessage' => $errorMessage,
            'logs' => $logs,
        ];
        if ($region) {
            $body['region'] = $region;
        }
        if ($stackName) {
            $body['stackName'] = $stackName;
        }
        if ($outputs) {
            $body['outputs'] = $outputs;
        }
        $this->client->request('POST', "/api/v1/deployments/$deploymentId/finished", [
            'json' => $body,
        ]);
    }

    /**
     * @return array{
     *     id: int,
     *     name: string,
     *     region: string|null,
     *     url: string|null,
     *     outputs: array<string, string>,
     *     app: array{id: int, name: string},
     * }
     *
     * @throws HttpExceptionInterface
     * @throws ExceptionInterface
     */
    public function getEnvironment(int $id): array
    {
        return $this->client->request('GET', '/api/v1/environments/' . $id)->toArray();
    }

    /**
     * @return array{
     *     id: int,
     *     name: string,
     *     region: string|null,
     *     url: string|null,
     *     outputs: array<string, string>,
     *     app: array{id: int, name: string},
     * }
     *
     * @throws HttpExceptionInterface
     * @throws ExceptionInterface
     */
    public function findEnvironment(string $teamSlug, string $appName, string $environment): array
    {
        return $this->client->request('GET', '/api/v1/environments/find?' . http_build_query([
            'teamSlug' => $teamSlug,
            'appName' => $appName,
            'environmentName' => $environment,
        ]))->toArray();
    }

    public function removeEnvironment(int $environmentId): void
    {
        $this->client->request('DELETE', '/api/v1/environments/' . $environmentId);
    }

    /**
     * @throws HttpExceptionInterface
     * @throws ExceptionInterface
     */
    public function startCommand(int $environmentId, string $command): int
    {
        return $this->client->request('POST', '/api/v1/commands/start', [
            'json' => [
                'environmentId' => $environmentId,
                'command' => $command,
            ],
        ])->toArray()['id'];
    }

    /**
     * @return array{
     *     status: 'not_started'|'started'|'success'|'failed',
     *     output: string,
     * }
     *
     * @throws HttpExceptionInterface
     * @throws ExceptionInterface
     */
    public function getCommand(int $id): array
    {
        return $this->client->request('GET', "/api/v1/commands/$id")->toArray();
    }

    /**
     * @return list<array{id: int, name: string, role_arn: string, team_id: int}>
     *
     * @throws HttpExceptionInterface
     * @throws ExceptionInterface
     */
    public function listAwsAccounts(): array
    {
        return $this->client->request('GET', '/api/v1/aws-accounts')->toArray();
    }

    /**
     * @return list<array{id: int, name: string}>
     *
     * @throws HttpExceptionInterface
     * @throws ExceptionInterface
     */
    public function listTeams(): array
    {
        return $this->client->request('GET', '/api/v1/teams')->toArray();
    }

    /**
     * @return array{
     *     region: string,
     *     template_url: string,
     *     stack_name: string,
     *     bref_cloud_account_id: string,
     *     unique_external_id: string,
     *     role_name: string|null,
     * }
     *
     * @throws HttpExceptionInterface
     * @throws ExceptionInterface
     */
    public function prepareConnectAwsAccount(int $teamId): array
    {
        return $this->client->request('GET', '/api/v1/aws-accounts/connect?team_id=' . $teamId)->toArray();
    }

    public function addAwsAccount(mixed $teamId, string $accountName, string $roleArn): void
    {
        $this->client->request('POST', '/api/v1/aws-accounts', [
            'json' => [
                'team_id' => $teamId,
                'name' => $accountName,
                'role_arn' => $roleArn,
            ],
        ]);
    }
}
