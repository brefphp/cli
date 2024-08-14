<?php declare(strict_types=1);

namespace Bref\Cli\Components;

use Amp\Process\Process;
use Amp\Process\ProcessException;
use Bref\Cli\BrefCloudClient;
use Bref\Cli\Cli\IO;
use Exception;
use Symfony\Component\Yaml\Yaml;
use function Amp\async;
use function Amp\ByteStream\buffer;

class ServerlessFramework
{
    /**
     * @param array{ accessKeyId: string, secretAccessKey: string, sessionToken: string } $awsCredentials
     * @throws ProcessException
     */
    public function deploy(int $deploymentId, string $environment, array $awsCredentials, BrefCloudClient $brefCloud): void
    {
        $process = $this->serverlessExec('deploy', $environment, $awsCredentials);
        $logs = '';
        async(function () use ($process, &$logs) {
            while (($chunk = $process->getStdout()->read()) !== null) {
                if (empty($chunk)) continue;
                IO::verbose($chunk);
                $logs .= $chunk;
            }
        });
        async(function () use ($process, &$logs) {
            while (($chunk = $process->getStderr()->read()) !== null) {
                if (empty($chunk)) continue;
                if (str_contains($chunk, 'https://dashboard.bref.sh')) continue;
                IO::verbose($chunk);
                $logs .= $chunk;
            }
        });
        $exitCode = $process->join();
        if ($exitCode > 0) {
            $logs .= "Error while running 'serverless deploy', deployment failed\n";
            IO::writeln("Error while running 'serverless deploy', deployment failed");
            $brefCloud->markDeploymentFinished($deploymentId, false, $logs);
            return;
        }

        $hasChanges = ! str_contains($logs, 'No changes to deploy. Deployment skipped.');
        if ($hasChanges) {
            try {
                $outputs = $this->retrieveOutputs($environment, $awsCredentials);
            } catch (Exception $e) {
                $logs .= $e->getMessage();
                $logs .= $e->getTraceAsString();
                $brefCloud->markDeploymentFinished($deploymentId, false, $logs);
                return;
            }

            $region = $outputs['region'];
            $stackName = $outputs['stack'];
            unset($outputs['stack'], $outputs['region']);

            $brefCloud->markDeploymentFinished($deploymentId, true, $logs, $region, $stackName, $outputs);
        } else {
            $brefCloud->markDeploymentFinished($deploymentId, true, $logs);
        }
    }

    /**
     * @param array{ accessKeyId: string, secretAccessKey: string, sessionToken: string } $awsCredentials
     * @return array<string, string>
     * @throws Exception
     */
    private function retrieveOutputs(string $environment, array $awsCredentials): array
    {
        $process = $this->serverlessExec('info', $environment, $awsCredentials);
        $process->join();
        $infoOutput = buffer($process->getStdout());
        // Remove non-ASCII characters
        $infoOutput = preg_replace('/[^\x00-\x7F]/', '', $infoOutput);
        if (! $infoOutput) {
            throw new Exception("Impossible to parse the output of 'serverless info':\n$infoOutput");
        }

        // Remove API Gateway URLs with invalid YAML content from the output,
        // i.e. lines containing `  ANY - ` or `  GET - ` or `  POST - ` or `  PUT - ` or `  DELETE - ` or `  PATCH - ` or `  OPTIONS - ` or `  HEAD - `
        $infoOutput = preg_replace('/^ {2}(ANY|GET|POST|PUT|DELETE|PATCH|OPTIONS|HEAD) - .*\n/m', '', $infoOutput);
        if (! $infoOutput) {
            throw new Exception("Impossible to parse the output of 'serverless info':\n$infoOutput");
        }

        try {
            $deployOutputs = Yaml::parse($infoOutput);
            if (! is_array($deployOutputs)) {
                throw new Exception('Invalid output in the "serverless info" output');
            }
            if (! isset($deployOutputs['stack']) || ! is_string($deployOutputs['stack'])) {
                throw new Exception('Missing stack in the "serverless info" output');
            }
            if (! isset($deployOutputs['region']) || ! is_string($deployOutputs['region'])) {
                throw new Exception('Missing region in the "serverless info" output');
            }
            $url = isset($deployOutputs['endpoint']) && is_string($deployOutputs['endpoint']) ? $deployOutputs['endpoint'] : null;
            // Remove the `ANY - ` prefix
            if ($url && str_starts_with($url, 'ANY - ')) {
                $url = substr($url, strlen('ANY - '));
            }
            // Special case for the `server-side-website` construct
            if (isset($deployOutputs['website']['url']) && is_string($deployOutputs['website']['url'])) {
                $url = $deployOutputs['website']['url'];
            }
            if (! isset($deployOutputs['Stack Outputs']) || ! is_array($deployOutputs['Stack Outputs'])) {
                throw new Exception('Missing stack outputs in the "serverless info" output');
            }
            $cfOutputs = $deployOutputs['Stack Outputs'];
            return array_merge([
                'stack' => $deployOutputs['stack'],
                'region' => $deployOutputs['region'],
            ], $url ? ['url' => $url] : [], $cfOutputs);
        } catch (Exception $e) {
            IO::verbose($e->getMessage());
            IO::verbose($infoOutput);
            // Try to extract the section with `Stack Outputs` and parse it
            // The regex below matches everything indented with 2 spaces below "Stack Outputs:"
            // If plugins add extra output afterward, it should be ignored.
            $outputsResults = preg_match('/Stack Outputs:\n(( {2}[ \S]+\n)+)/', $infoOutput, $matches);
            // Also try to extract the stack name and region
            $stackResults = preg_match('/stack: (.*)\n/', $infoOutput, $matches);
            $regionResults = preg_match('/region: (.*)\n/', $infoOutput, $matches);
            if ($outputsResults && $stackResults && $regionResults) {
                try {
                    $stackOutputs = Yaml::parse($matches[1]);
                    if (! is_array($stackOutputs)) {
                        throw new Exception('Invalid stack outputs in the "serverless info" output');
                    }
                    $stackName = $matches[2];
                    if (! is_string($stackName)) {
                        throw new Exception('Invalid stack name in the "serverless info" output');
                    }
                    $region = $matches[3];
                    if (! is_string($region)) {
                        throw new Exception('Invalid region in the "serverless info" output');
                    }
                    return array_merge([
                        'stack' => $stackName,
                        'region' => $region,
                    ], $stackOutputs);
                } catch (Exception) {
                    // Pass to generic error
                }
            }
        }

        throw new Exception("Impossible to parse the output of 'serverless info':\n$infoOutput");
    }

    /**
     * @param array{ accessKeyId: string, secretAccessKey: string, sessionToken: string } $awsCredentials
     * @throws ProcessException
     */
    private function serverlessExec(string $command, string $environment, array $awsCredentials): Process
    {
        $env = [
            'SLS_DISABLE_AUTO_UPDATE' => '1',
            'AWS_ACCESS_KEY_ID' => $awsCredentials['accessKeyId'],
            'AWS_SECRET_ACCESS_KEY' => $awsCredentials['secretAccessKey'],
            'AWS_SESSION_TOKEN' => $awsCredentials['sessionToken'],
        ];
        // Merge the current environment with the AWS credentials
        $env = array_merge(getenv(), $env);
        return Process::start(['npx', '--yes', '@bref.sh/serverless', $command, '--verbose', '--stage', $environment], environment: $env);
    }
}