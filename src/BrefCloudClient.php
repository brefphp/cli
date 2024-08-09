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

    public function __construct(string $token = null)
    {
        $this->url = self::getUrl();
        if ($token === null) {
            $token = Token::getToken($this->url);
        }

        $this->client = HttpClient::createForBaseUri($this->url, [
            'timeout' => 10,
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
        return $this->client->request('GET', '/api/user')->toArray();
    }

    /**
     * @param array<string, string> $config
     * @return array{
     *     deploymentId: int,
     *     status: string,
     *     message: string,
     *     url: string,
     *     outputs?: array<string, string>,
     *     credentials?: array{
     *         accessKeyId: string,
     *         secretAccessKey: string,
     *         sessionToken: string,
     *     },
     * }
     *
     * @throws HttpExceptionInterface
     * @throws ExceptionInterface
     */
    public function startDeployment(string $environment, array $config, string|null $gitRef, string $gitMessage, ?string $awsAccountName = null): array
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
        return $this->client->request('POST', '/api/deployments', [
            'json' => $body,
        ])->toArray();
    }

    /**
     * @return array{deploymentId: int, status: string, message: string, url: string, outputs?: array<string, string>}
     *
     * @throws HttpExceptionInterface
     * @throws ExceptionInterface
     */
    public function getDeployment(int $deploymentId): array
    {
        return $this->client->request('GET', "/api/deployments/$deploymentId")->toArray();
    }

    public function markDeploymentFinished(
        int $deploymentId,
        bool $success,
        string $logs,
        ?string $region = null,
        ?string $stackName = null,
        ?array $outputs = null,
    ): void
    {
        $body = [
            'success' => $success,
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
        $this->client->request('POST', "/api/deployments/$deploymentId/finished", [
            'json' => $body,
        ]);
    }

    /**
     * @return array{success: bool, output: string}
     */
    public function startCommand(string $appName, string $environment, string $command): array
    {
        return $this->client->request('POST', '/api/commands', [
            'json' => [
                'appName' => $appName,
                'environmentName' => $environment,
                'consoleCommand' => $command,
            ],
        ])->toArray();
    }
}