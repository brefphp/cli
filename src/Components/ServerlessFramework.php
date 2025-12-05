<?php declare(strict_types=1);

namespace Bref\Cli\Components;

use Amp\Process\Process;
use Amp\Process\ProcessException;
use Bref\Cli\BrefCloudClient;
use Bref\Cli\Cli\IO;
use Exception;
use Revolt\EventLoop;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Yaml\Yaml;
use Throwable;
use function Amp\async;
use function Amp\ByteStream\buffer;

class ServerlessFramework
{
    private const IGNORED_LOGS = [
        'https://dashboard.bref.sh',
        '(node:83031) [DEP0040] DeprecationWarning: The `punycode` module is deprecated. Please use a userland alternative instead.',
        '(Use `node --trace-deprecation ...` to show where the warning was created)',
    ];

    /**
     * @param array{ accessKeyId: string, secretAccessKey: string, sessionToken: string } $awsCredentials
     * @throws ProcessException
     */
    public function deploy(int $deploymentId, string $environment, array $awsCredentials, BrefCloudClient $brefCloud, InputInterface $input): void
    {
        $options = [];
        if ($input->hasOption('force')) {
            $options[] = '--force';
        }
        if ($input->hasOption('config') && $input->getOption('config')) {
            $configFile = (string) $input->getOption('config');
            $options[] = '--config';
            $options[] = $configFile;
        } else {
            $configFile = null;
        }

        $newLogs = '';
        $entireSlsOutput = '';

        try {

            $process = $this->serverlessExec('deploy', $environment, $awsCredentials, $options);
            async(function () use ($process, &$newLogs, &$entireSlsOutput) {
                while (($chunk = $process->getStdout()->read()) !== null) {
                    if (empty($chunk)) continue;
                    foreach (self::IGNORED_LOGS as $ignoredLog) {
                        if (str_contains($chunk, $ignoredLog)) continue 2;
                    }
                    IO::verbose($chunk);
                    $newLogs .= $chunk;
                    $entireSlsOutput .= $chunk;
                }
            });
            async(function () use ($process, &$newLogs, &$entireSlsOutput) {
                while (($chunk = $process->getStderr()->read()) !== null) {
                    if (empty($chunk)) continue;
                    foreach (self::IGNORED_LOGS as $ignoredLog) {
                        if (str_contains($chunk, $ignoredLog)) continue 2;
                    }
                    IO::verbose($chunk);
                    $newLogs .= $chunk;
                    $entireSlsOutput .= $chunk;
                }
            });
            // Send logs to Bref Cloud every x seconds
            $logPusherTimer = EventLoop::repeat(3, function () use ($brefCloud, $deploymentId, &$newLogs) {
                if ($newLogs === '') {
                    return;
                }
                try {
                    $brefCloud->pushDeploymentLogs($deploymentId, $newLogs);

                    $newLogs = '';
                } catch (\Throwable $e) {
                    // Log pushing is best-effort, this is to avoid crashing the event loop
                    IO::verbose('Failed to push deployment logs: ' . $e->getMessage());
                }
            });
            $exitCode = $process->join();
            EventLoop::cancel($logPusherTimer);

            if ($exitCode > 0) {
                $newLogs .= "Error while running 'serverless deploy', deployment failed\n";
                IO::writeln("Error while running 'serverless deploy', deployment failed");

                // If `npx` is not installed throw a clear error message
                if (str_contains($entireSlsOutput, 'npo: command not found')) {
                    $brefCloud->markDeploymentFinished($deploymentId, false, 'NPM is not installed. Please make sure Node and NPM are installed: https://docs.npmjs.com/downloading-and-installing-node-js-and-npm', $newLogs);
                    return;
                }

                $errorMessage = $this->findErrorMessageInServerlessOutput($entireSlsOutput);
                $brefCloud->markDeploymentFinished($deploymentId, false, 'Serverless Framework error: ' . $errorMessage, $newLogs);
                return;
            }

            $hasChanges = ! str_contains($newLogs, 'No changes to deploy. Deployment skipped.');
            if ($hasChanges) {
                $outputs = $this->retrieveOutputs($environment, $awsCredentials, $configFile);

                $region = $outputs['region'];
                $stackName = $outputs['stack'];
                unset($outputs['stack'], $outputs['region']);

                $brefCloud->markDeploymentFinished($deploymentId, true, null, $newLogs, $region, $stackName, $outputs);
            } else {
                $brefCloud->markDeploymentFinished($deploymentId, true, null, $newLogs);
            }

        } catch (Throwable $e) {
            // We don't want the CLI to fail and the deployment to stay in "deploying" status in Cloud
            $newLogs .= 'Uncaught error: ' . $e->getMessage();
            $newLogs .= $e->getTraceAsString();
            $brefCloud->markDeploymentFinished($deploymentId, false, $e->getMessage(), $newLogs);

            throw $e;
        }
    }

    /**
     * @param array{ accessKeyId: string, secretAccessKey: string, sessionToken: string } $awsCredentials
     * @return array<string, string>
     * @throws Exception
     */
    private function retrieveOutputs(string $environment, array $awsCredentials, ?string $configFile): array
    {
        $options = [];
        if ($configFile) {
            $options[] = '--config';
            $options[] = $configFile;
        }

        $process = $this->serverlessExec('info', $environment, $awsCredentials, $options);
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
            if (!$url && isset($deployOutputs['endpoints']) && is_array($deployOutputs['endpoints'])) {
                $url = reset($deployOutputs['endpoints']);
            }
            // Special case for the `server-side-website` construct
            if (isset($deployOutputs['website']['url']) && is_string($deployOutputs['website']['url'])) {
                $url = $deployOutputs['website']['url'];
            }
            if (! isset($deployOutputs['Stack Outputs']) || ! is_array($deployOutputs['Stack Outputs'])) {
                throw new Exception('Missing stack outputs in the "serverless info" output');
            }
            $cfOutputs = $this->cleanupCfOutputs($deployOutputs['Stack Outputs']);
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
            $stackResults = preg_match('/stack: (.*)\n/', $infoOutput, $stackMatches);
            $regionResults = preg_match('/region: (.*)\n/', $infoOutput, $regionMatches);
            if ($outputsResults && $stackResults && $regionResults) {
                try {
                    $stackOutputs = Yaml::parse($matches[1]);
                    if (! is_array($stackOutputs)) {
                        throw new Exception('Invalid stack outputs in the "serverless info" output');
                    }
                    $stackOutputs = $this->cleanupCfOutputs($stackOutputs);

                    $stackName = $stackMatches[1];
                    if (! is_string($stackName)) {
                        throw new Exception('Invalid stack name in the "serverless info" output');
                    }

                    $region = $regionMatches[1];
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
     * @param list<string> $options
     * @throws ProcessException
     */
    private function serverlessExec(string $command, string $environment, array $awsCredentials, array $options): Process
    {
        $env = [
            'SLS_DISABLE_AUTO_UPDATE' => '1',
            'AWS_ACCESS_KEY_ID' => $awsCredentials['accessKeyId'],
            'AWS_SECRET_ACCESS_KEY' => $awsCredentials['secretAccessKey'],
            'AWS_SESSION_TOKEN' => $awsCredentials['sessionToken'],
        ];
        // Merge the current environment with the AWS credentials
        $env = array_merge(getenv(), $env);

        $processArgs = ['npx', '--yes', 'osls', $command, '--verbose', '--stage', $environment, ...$options];

        IO::verbose('Running "' . implode(' ', $processArgs) . '"');

        return Process::start($processArgs, environment: $env);
    }

    /**
     * @param array<string, string> $outputs
     * @return array<string, string>
     */
    private function cleanupCfOutputs(array $outputs): array
    {
        return array_filter($outputs, function (string $name): bool {
            if ($name === 'ServerlessDeploymentBucketName') return false;
            if ($name === 'HttpApiId') return false;
            if (str_contains($name, 'LambdaFunctionQualifiedArn')) return false;
            return true;
        }, ARRAY_FILTER_USE_KEY);
    }

    private function findErrorMessageInServerlessOutput(string $entireSlsOutput): string
    {
        // Try to find the next line after `Error:\n`
        $lines = explode("\n", trim($entireSlsOutput));
        foreach ($lines as $i => $line) {
            if ($line === 'Error:' && isset($lines[$i + 1])) {
                return $lines[$i + 1];
            }
        }
        // Return the last line or fallback to a generic message
        return $line ?: 'The "serverless deploy" command failed with an unknown error.';
    }
}