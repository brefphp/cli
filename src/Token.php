<?php declare(strict_types=1);

namespace Bref\Cli;

use Exception;
use JsonException;
use Symfony\Component\Filesystem\Filesystem;

class Token
{
    public static function storeToken(string $url, string $token): void
    {
        $config = self::loadConfig();
        if (! isset($config['tokens']) || ! is_array($config['tokens'])) {
            $config['tokens'] = [];
        }
        $config['tokens'][$url] = $token;
        self::saveConfig($config);
    }

    public static function getToken(string $url): string
    {
        // For CI/CD
        $envToken = $_SERVER['BREF_TOKEN'] ?? null;
        if (is_string($envToken) && $envToken !== '') {
            return $envToken;
        }

        $config = self::loadConfig();
        $tokens = $config['tokens'] ?? null;
        $token = is_array($tokens) ? ($tokens[$url] ?? null) : null;
        if (! is_string($token) || $token === '') {
            throw new Exception('You are not logged in Bref Cloud. Please run "bref login" first.');
        }
        return $token;
    }

    /**
     * @return array<string, mixed>
     */
    private static function loadConfig(): array
    {
        $configPath = self::getConfigPath();
        if (! file_exists($configPath)) return [];
        $json = file_get_contents($configPath);
        if ($json === false) return [];
        try {
            $array = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($array)) return [];
            $config = [];
            foreach ($array as $key => $value) {
                if (is_string($key)) {
                    $config[$key] = $value;
                }
            }
            return $config;
        } catch (JsonException) {
            return [];
        }
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function saveConfig(array $config): void
    {
        $configPath = self::getConfigPath();
        $json = json_encode($config, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        $fs = new Filesystem;
        $fs->dumpFile($configPath, $json);
    }

    private static function getConfigPath(): string
    {
        $home = $_SERVER['HOME'] ?? null;
        if ($home === null && isset($_SERVER['HOMEDRIVE'], $_SERVER['HOMEPATH'])) {
            $home = $_SERVER['HOMEDRIVE'] . $_SERVER['HOMEPATH'];
        }
        if (! is_string($home) || $home === '') {
            throw new Exception('Cannot determine home directory');
        }

        return $home . DIRECTORY_SEPARATOR . '.bref' . DIRECTORY_SEPARATOR . 'config.json';
    }
}