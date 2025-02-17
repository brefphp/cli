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
        if (! isset($config['tokens'])) $config['tokens'] = [];
        $config['tokens'][$url] = $token;
        self::saveConfig($config);
    }

    public static function getToken(string $url): string
    {
        // For CI/CD
        if ($_SERVER['BREF_TOKEN'] ?? false) {
            return $_SERVER['BREF_TOKEN'];
        }

        $config = self::loadConfig();
        $token = $config['tokens'][$url] ?? null;
        if (! $token) {
            throw new Exception('You are not logged in Bref Cloud. Please run "bref login" first.');
        }
        return $token;
    }

    private static function loadConfig(): array
    {
        $configPath = self::getConfigPath();
        if (! file_exists($configPath)) return [];
        $json = file_get_contents($configPath);
        if ($json === false) return [];
        try {
            $array = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($array)) return [];
            return $array;
        } catch (JsonException) {
            return [];
        }
    }

    private static function saveConfig(array $config): void
    {
        $configPath = self::getConfigPath();
        $json = json_encode($config, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        $fs = new Filesystem;
        $fs->dumpFile($configPath, $json);
    }

    private static function getConfigPath(): string
    {
        $home = $_SERVER['HOME'] ?? ($_SERVER['HOMEDRIVE'] . $_SERVER['HOMEPATH']);

        return $home . DIRECTORY_SEPARATOR . '.bref' . DIRECTORY_SEPARATOR . 'config.json';
    }
}