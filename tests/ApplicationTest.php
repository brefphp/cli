<?php declare(strict_types=1);

namespace Bref\Cli\Test;

use Bref\Cli\Application;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\ClientException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class ApplicationTest extends TestCase
{
    public function test_a_forbidden_error_shows_the_message_of_the_api(): void
    {
        $e = Application::prettifyException($this->apiError(403, [
            'message' => 'Bref Cloud is not authorized to access the AWS account "production": it could not assume the role arn:aws:iam::123456789012:role/BrefCloudAccess.',
        ]));

        $this->assertSame(
            'Bref Cloud API error: [403] Bref Cloud is not authorized to access the AWS account "production": it could not assume the role arn:aws:iam::123456789012:role/BrefCloudAccess.',
            $e->getMessage(),
        );
    }

    public function test_a_failed_authorization_suggests_logging_in_to_another_team(): void
    {
        $expected = 'Bref Cloud API error: [403] Forbidden. You do not have the required permissions. Do you need to login to a different team?';

        $this->assertSame($expected, Application::prettifyException($this->apiError(403, ['message' => 'This action is unauthorized.']))->getMessage());
        $this->assertSame($expected, Application::prettifyException($this->apiError(403, ['message' => '']))->getMessage());
        $this->assertSame($expected, Application::prettifyException($this->apiError(403, []))->getMessage());
    }

    /**
     * @param array<string, mixed> $body
     */
    private function apiError(int $statusCode, array $body): ClientException
    {
        $client = new MockHttpClient(new MockResponse(json_encode($body, JSON_THROW_ON_ERROR), [
            'http_code' => $statusCode,
            'response_headers' => ['content-type' => 'application/json'],
        ]));

        return new ClientException($client->request('POST', 'https://bref.cloud/api/v1/deployments'));
    }
}
