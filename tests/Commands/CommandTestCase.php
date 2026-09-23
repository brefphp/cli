<?php declare(strict_types=1);

namespace Bref\Cli\Test\Commands;

use Bref\Cli\BrefCloudClient;
use Laravel\AgentDetector\AgentDetector;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

abstract class CommandTestCase extends TestCase
{
    /** @var array<string, string|false> */
    private array $agentVariables = [];
    /** @var list<string> Paths and query strings of the requests sent to Bref Cloud */
    protected array $requests = [];

    protected function setUp(): void
    {
        // The tests may run inside an AI agent (e.g. Claude Code): start from a human
        foreach ([...array_keys(AgentDetector::AGENT_ENV_VARS), 'AI_AGENT'] as $variable) {
            $this->agentVariables[$variable] = getenv($variable);
            putenv($variable);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->agentVariables as $variable => $value) {
            putenv($value === false ? $variable : "$variable=$value");
        }
    }

    protected function runByAnAgent(): void
    {
        putenv('AI_AGENT=test-agent');
    }

    /**
     * @param array<string, mixed> $routes Responses indexed by path, a MockResponse or data to return as JSON.
     */
    protected function brefCloud(array $routes): BrefCloudClient
    {
        $client = new MockHttpClient(function (string $method, string $url) use ($routes): MockResponse {
            $path = (string) parse_url($url, PHP_URL_PATH);
            $query = (string) parse_url($url, PHP_URL_QUERY);
            $this->requests[] = urldecode($path . ($query ? "?$query" : ''));
            if (! array_key_exists($path, $routes)) {
                $this->fail("Unexpected request: $method $url");
            }

            return $routes[$path] instanceof MockResponse ? $routes[$path] : $this->json($routes[$path]);
        }, 'https://bref.cloud');

        return new BrefCloudClient(client: $client);
    }

    /**
     * Not `JsonMockResponse`, which Symfony 5 does not have.
     */
    protected function json(mixed $data, int $status = 200): MockResponse
    {
        return new MockResponse((string) json_encode($data), [
            'http_code' => $status,
            'response_headers' => ['content-type' => 'application/json'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function environment(): array
    {
        return ['id' => 12, 'name' => 'prod', 'region' => 'us-east-1', 'url' => null, 'outputs' => [], 'app' => ['id' => 3, 'name' => 'shop'], 'aws_account_id' => 1];
    }
}
