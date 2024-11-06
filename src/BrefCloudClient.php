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
        return $this->client->request('GET', '/api/v1/user')->toArray();
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
        return $this->client->request('POST', '/api/v1/deployments', [
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
        $this->client->request('POST', "/api/v1/deployments/$deploymentId/finished", [
            'json' => $body,
        ]);
    }

    /**
     * @return array{success: bool, output: string}
     *
     * @throws HttpExceptionInterface
     * @throws ExceptionInterface
     */
    public function startCommand(string $appName, string $environment, string $command): array
    {
        return $this->client->request('POST', '/api/v1/commands', [
            'json' => [
                'appName' => $appName,
                'environmentName' => $environment,
                'consoleCommand' => $command,
            ],
        ])->toArray();
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

    /**
     * @return string S3 URL
     */
    public function uploadPackage(string $hash, string $path, string $team): string
    {
        $signedUrl = $this->retrieveS3SignedUrlForCodeUpload($team, $hash);

        if (isset($signedUrl['uploadUrl'], $signedUrl['uploadHeaders'])) {
            HttpClient::create()->request(
                'PUT',
                $signedUrl['uploadUrl'],
                [
                    'headers' => $signedUrl['uploadHeaders'],
                    'body' => file_get_contents($path),
                ]
            );
        }

        // @TODO: return this ready-to-go from the deployments/packages API
        [$_, $s3Path] = explode('.amazonaws.com/', $signedUrl['downloadUrl']);

        // @TODO: the API needs to be compliant with multiple regions and return which bucket was used
        return "s3://bref-cloud-staging-storage-kpss5zgu7abr/$s3Path";
    }

    /**
     * @param string $hash
     * @return array{uploadUrl: string, uploadHeaders: array<string, string>, downloadUrl: string}
     */
    private function retrieveS3SignedUrlForCodeUpload(string $team, string $hash): array
    {
        $response = $this->client->request('POST', '/api/v1/deployments/packages', [
            'body' => [
                'hash' => $hash,
                'team' => $team,
            ],
        ]);

        return $response->toArray();
    }


}
