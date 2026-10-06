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
        $commonOptions = $this->commonOptions($input);
        $options = $commonOptions;
        if ($input->hasOption('force')) {
            $options[] = '--force';
        }
        $oslsPackage = ($input->hasOption('osls4') && $input->getOption('osls4')) ? 'osls@4' : 'osls@3';

        $newLogs = '';
        // The whole output ($newLogs is emptied when pushed to Bref Cloud)
        $output = '';

        try {

            $process = $this->serverlessExec($oslsPackage, 'deploy', $environment, $awsCredentials, $options);
            async(function () use ($process, &$newLogs, &$output) {
                while (($chunk = $process->getStdout()->read()) !== null) {
                    if (empty($chunk)) continue;
                    foreach (self::IGNORED_LOGS as $ignoredLog) {
                        if (str_contains($chunk, $ignoredLog)) continue 2;
                    }
                    IO::verbose($chunk);
                    $newLogs .= $chunk;
                    $output .= $chunk;
                }
            });
            async(function () use ($process, &$newLogs, &$output) {
                while (($chunk = $process->getStderr()->read()) !== null) {
                    if (empty($chunk)) continue;
                    foreach (self::IGNORED_LOGS as $ignoredLog) {
                        if (str_contains($chunk, $ignoredLog)) continue 2;
                    }
                    IO::verbose($chunk);
                    $newLogs .= $chunk;
                    $output .= $chunk;
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

                // Bref Cloud finds the error in the logs.
                // The stack is only known after a successful deployment: if this one created it, it is sent
                // so that removing the environment deletes it.
                [$stackName, $region] = $this->findCreatedStack($output) ?? [null, null];
                $brefCloud->markDeploymentFinished($deploymentId, false, null, $newLogs, $region, $stackName);
                return;
            }

            $hasChanges = ! str_contains($newLogs, 'No changes to deploy. Deployment skipped.');
            if ($hasChanges) {
                $outputs = $this->retrieveOutputs($oslsPackage, $environment, $awsCredentials, $commonOptions);

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
     * The options that both `serverless deploy` and the `serverless info` that follows it need, to resolve the
     * same configuration (e.g. `${param:...}` variables).
     *
     * @return list<string>
     */
    public function commonOptions(InputInterface $input): array
    {
        $options = [];
        $configFile = $input->hasOption('config') ? $input->getOption('config') : null;
        if (is_string($configFile) && $configFile !== '') {
            $options[] = '--config';
            $options[] = $configFile;
        }
        $params = $input->hasOption('param') ? $input->getOption('param') : [];
        foreach (is_array($params) ? $params : [] as $param) {
            if (is_string($param)) {
                $options[] = '--param';
                $options[] = $param;
            }
        }

        return $options;
    }

    /**
     * The stack that the deployment created, if it did.
     *
     * With `--verbose`, osls logs the events of the stack. The first one is the stack's own (nested stacks come after):
     * `CREATE_IN_PROGRESS` when osls creates it, `UPDATE_IN_PROGRESS` when it already existed (e.g. deployed some
     * other way before), and there is none when the deployment failed before CloudFormation.
     *
     * @return array{string, string}|null The stack name and its region.
     */
    public function findCreatedStack(string $output): ?array
    {
        if (! preg_match('/^\s*(\w+) - AWS::CloudFormation::Stack - (\S+)\s*$/m', $output, $firstStackEvent)) {
            return null;
        }
        if ($firstStackEvent[1] !== 'CREATE_IN_PROGRESS') {
            return null;
        }
        // "Deploying <service> to stage <stage> (<region>)"
        if (! preg_match('/^\s*Deploying .+ to stage .+ \(([a-z0-9-]+)\)\s*$/m', $output, $deploying)) {
            return null;
        }

        return [$firstStackEvent[2], $deploying[1]];
    }

    /**
     * @param array{ accessKeyId: string, secretAccessKey: string, sessionToken: string } $awsCredentials
     * @param list<string> $options
     * @return array<string, string>
     * @throws Exception
     */
    private function retrieveOutputs(string $oslsPackage, string $environment, array $awsCredentials, array $options): array
    {
        $process = $this->serverlessExec($oslsPackage, 'info', $environment, $awsCredentials, $options);
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
                $firstEndpoint = reset($deployOutputs['endpoints']);
                $url = is_string($firstEndpoint) ? $firstEndpoint : null;
            }
            // Special case for the `server-side-website` construct
            if (isset($deployOutputs['website']) && is_array($deployOutputs['website']) && isset($deployOutputs['website']['url']) && is_string($deployOutputs['website']['url'])) {
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
                    $region = $regionMatches[1];

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
    private function serverlessExec(string $oslsPackage, string $command, string $environment, array $awsCredentials, array $options): Process
    {
        $env = [
            'SLS_DISABLE_AUTO_UPDATE' => '1',
            'AWS_ACCESS_KEY_ID' => $awsCredentials['accessKeyId'],
            'AWS_SECRET_ACCESS_KEY' => $awsCredentials['secretAccessKey'],
            'AWS_SESSION_TOKEN' => $awsCredentials['sessionToken'],
            // The output is parsed (`serverless info`) and pushed as the deployment logs: no colors, even when
            // `FORCE_COLOR` is set, e.g. by a CI or an agent (`--no-color` doesn't win over `FORCE_COLOR`)
            'FORCE_COLOR' => '0',
        ];
        // Merge the current environment with the AWS credentials
        $env = array_merge(getenv(), $env);

        $processArgs = ['npx', '--yes', $oslsPackage, $command, '--verbose', '--stage', $environment, ...$options];

        IO::verbose('Running "' . implode(' ', $processArgs) . '"');

        return Process::start($processArgs, environment: $env);
    }

    /**
     * @return array<string, string>
     */
    private function cleanupCfOutputs(mixed $outputs): array
    {
        if (! is_array($outputs)) {
            return [];
        }

        $result = [];
        foreach ($outputs as $name => $value) {
            if (! is_string($name) || ! is_string($value)) {
                continue;
            }
            if ($name === 'ServerlessDeploymentBucketName') continue;
            if ($name === 'HttpApiId') continue;
            if (str_contains($name, 'LambdaFunctionQualifiedArn')) continue;
            $result[$name] = $value;
        }

        return $result;
    }
}