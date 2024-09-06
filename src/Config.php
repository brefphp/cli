<?php declare(strict_types=1);

namespace Bref\Cli;

use Exception;
use Symfony\Component\Yaml\Yaml;

class Config
{
    /**
     * @return array{name: string, org: string, type: string}
     * @throws Exception
     */
    public static function loadConfig(): array
    {
        if (is_file('serverless.yml')) {
            $serverlessConfig = self::readYamlFile('serverless.yml');
            if (empty($serverlessConfig['service']) || str_contains((string) $serverlessConfig['service'], '$')) {
                throw new Exception('The "service" name in "serverless.yml" cannot contain variables, it is not supported by Bref Cloud');
            }
            $org = (string) ($serverlessConfig['custom']['brefOrg'] ?? '');
            if (empty($org)) {
                throw new Exception('To deploy a Serverless Framework project with Bref Cloud you must set the organization name in the "custom.brefOrg" field in "serverless.yml"');
            }
            if (str_contains($serverlessConfig['custom']['brefOrg'], '$')) {
                throw new Exception('The "service" name in "serverless.yml" cannot contain variables, it is not supported by Bref Cloud');
            }
            return [
                'name' => (string) $serverlessConfig['service'],
                'org' => $org,
                'type' => 'serverless-framework',
            ];
        }

        throw new Exception('TODO: read bref.php config');
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
