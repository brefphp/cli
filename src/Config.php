<?php declare(strict_types=1);

namespace Bref\Cli;

use Exception;
use JsonException;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

class Config
{
    /**
     * @return array{name: string, team: string, type: string}
     * @throws Exception
     */
    public static function loadConfig(?string $fileName, ?string $environment, ?string $overrideTeam): array
    {
        if ($fileName) {
            $fileExtension = pathinfo($fileName, PATHINFO_EXTENSION);
            if ($fileExtension === 'yml' || $fileExtension === 'yaml') {
                return self::loadServerlessConfig($fileName, $overrideTeam);
            }
            return self::loadBrefConfig($fileName, $environment, $overrideTeam);
        }

        if (is_file('bref.php')) {
            return self::loadBrefConfig('bref.php', $environment, $overrideTeam);
        }

        if (is_file('serverless.yml')) {
            return self::loadServerlessConfig('serverless.yml', $overrideTeam);
        }

        throw new Exception('No "serverless.yml" file found in the current directory');
    }

    /**
     * @return array{name: string, team: string, type: string}
     */
    private static function loadServerlessConfig(string $fileName, ?string $overrideTeam): array
    {
        $serverlessConfig = self::readYamlFile($fileName);
        if (empty($serverlessConfig['service']) || ! is_string($serverlessConfig['service']) || str_contains($serverlessConfig['service'], '$')) {
            throw new Exception('The "service" name in "serverless.yml" cannot contain variables, it is not supported by Bref Cloud');
        }
        $team = (string) ($serverlessConfig['bref']['team'] ?? $serverlessConfig['custom']['bref']['team'] ?? '');
        if (empty($team)) {
            throw new Exception('To deploy a Serverless Framework project with Bref Cloud you must set the team name in the "bref.team" field in "serverless.yml"');
        }
        if (str_contains($team, '$')) {
            throw new Exception('The "service" name in "serverless.yml" cannot contain variables, it is not supported by Bref Cloud');
        }
        return [
            'name' => $serverlessConfig['service'],
            'team' => $overrideTeam ?: $team,
            'type' => 'serverless-framework',
            // Health checks are automatically enabled if the package is installed
            'healthChecks' => file_exists('vendor/bref/laravel-health-check/composer.json'),
        ];
    }

    /**
     * @return array{name: string, team: string, type: string}
     */
    private static function loadBrefConfig(string $fileName, ?string $environment, ?string $overrideTeam): array
    {
        $absolute = realpath($fileName);
        $file = basename($absolute);
        $dir = dirname($absolute);

        // Execute the bref.php file to get the configuration via stdout
        $process = new Process(['php', $file]);
        $process->setWorkingDirectory($dir);
        if ($environment) {
            $processVariables = getenv();
            $process->setEnv([
                ...$processVariables,
                'BREF_CLI_ENV' => $environment,
            ]);
        }
        $process->run();
        if ($process->getExitCode() !== 0) {
            throw new Exception('The "bref.php" file failed to execute: ' . $process->getOutput() . $process->getErrorOutput());
        }
        $output = $process->getOutput();

        try {
            $config = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new Exception("The 'bref.php' file returned invalid JSON output\n$output");
        }
        if (! is_array($config)) {
            throw new Exception('The "bref.php" file must return an array, got: ' . $output);
        }

        // At this point we only support deploying 1 app at a time
        // So we'll merge the app configuration at the root
        if (isset($config['apps'])) {
            $config = [
                ...$config['apps'][0],
                'packages' => $config['packages'],
                'team' => $overrideTeam ?: $config['team'],
            ];
        }

        return $config;
    }

    /**
     * @return array<array-key, mixed>
     * @throws Exception
     */
    private static function readYamlFile(string $fileName): array
    {
        if (! is_file($fileName)) {
            throw new Exception("Cannot parse \"$fileName\": file not found");
        }
        try {
            $fileContent = file_get_contents($fileName);
            if ($fileContent === false) {
                throw new Exception("Cannot read file $fileName");
            }
            $yamlContent = Yaml::parse($fileContent, Yaml::PARSE_CUSTOM_TAGS);
            if (! is_array($yamlContent) || empty($yamlContent)) {
                throw new Exception("invalid YAML content");
            }
            return $yamlContent;
        } catch (Exception $e) {
            throw new Exception("Cannot parse \"$fileName\": " . $e->getMessage(), 0, $e);
        }
    }
}
