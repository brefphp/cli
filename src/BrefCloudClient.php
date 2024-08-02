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
            $token = Config::getToken($this->url);
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
        if ($_SERVER['BREF_LOCAL'] ?? false) {
            return self::LOCAL_URL;
        }
        // TODO switch to prod
        return self::STAGING_URL;
    }

    /**
     * @throws HttpExceptionInterface
     * @throws ExceptionInterface
     */
    public function getUserInfo(): array
    {
        return $this->client->request('GET', '/api/user')->toArray();
    }
}