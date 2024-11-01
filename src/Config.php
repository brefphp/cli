<?php declare(strict_types=1);

namespace Bref\Cli;

use Exception;
use Symfony\Component\Yaml\Yaml;
use ZipArchive;

class Config
{
    /**
     * @return array{name: string, team: string, type: string}
     * @throws Exception
     */
    public static function loadConfig(BrefCloudClient $client, string $directory): array
    {
        if (is_file($directory . '/bref.php')) {
            $config = require $directory . '/bref.php';

            // @TODO: find a way to bring the Bref\Cloud\Laravel as a dependency to the CLI.
            if (! $config instanceof \Bref\Cloud\Laravel) {
                throw new Exception('The "bref.php" file must return an instance of \Bref\Cloud\Laravel');
            }

            // @TODO I don't think this is the best place to put this code, but
            // we need access to the entire Laravel object before it gets serialized
            // so that we can process it properly.
            // The important attributes here are:
            // - $path
            // - $exclude
            // - S3 source code URL
            // - @TODO: assets?
            [$hash, $path] = self::zipProjectContents($directory, $config);

            $s3Path = $client->uploadSourceCodeToS3($hash, $path, $config->team);

            $php = $config->path($s3Path)->serialize();

            return [
                'name' => $config->name,
                'team' => $config->team,
                'type' => 'laravel',
                'php' => $php,
            ];
        }

        if (is_file('serverless.yml')) {
            $serverlessConfig = self::readYamlFile('serverless.yml');
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
                'team' => $team,
                'type' => 'serverless-framework',
            ];
        }

        throw new Exception('No "serverless.yml" file found in the current directory');
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

    private static function zipProjectContents(string $directory, \Bref\Cloud\Laravel $config)
    {
        if (! is_dir($directory . '.bref/')) {
            mkdir($directory . '.bref/');
        }

        $archive = $directory . '.bref/project.zip';

        $zip = new ZipArchive;

        // @TODO should we generate unique names for each time we go through here to not
        // override existing zip files?
        $zip->open($archive, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        // @TODO: we need to take $config->path into consideration. For now only the entire
        // current path is being zipped.
        self::addFolderContentsToZipArchive($zip, $directory);

        $zip->close();

        return [hash_file('sha256', $archive), $archive];
    }

    private static function addFolderContentsToZipArchive(ZipArchive $zip, $rootDirectory, $subfolder = ''): void
    {
        $contents = scandir($rootDirectory . '/' . $subfolder);

        foreach ($contents as $content) {
            if (in_array($content, ['.', '..', '.bref', '.git', '.idea'])) {
                continue;
            }

            $relativePath = $subfolder . '/' . $content;

            $absolutePath = $rootDirectory . '/' . $relativePath;

            // @TODO: work out exclude logic that has to consider wildcard caracters
            // such as `node_modules/**` or `tests/**/*.php`


            if (is_dir($absolutePath)) {
                self::addFolderContentsToZipArchive($zip, $rootDirectory, $relativePath . DIRECTORY_SEPARATOR);
            } elseif (is_file($absolutePath)) {
                $zip->addFile($absolutePath, $relativePath);
            } else {
                throw new Exception('Invalid path: ' . $absolutePath);
            }
        }
    }

}
