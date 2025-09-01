<?php declare(strict_types=1);

namespace Bref\Cli\Commands;

use Amp\ByteStream\BufferException;
use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Interceptor\SetRequestHeader;
use Amp\Http\Client\Interceptor\SetRequestTimeout;
use Amp\Http\Client\Request;
use Amp\Http\Client\StreamedContent;
use Amp\Http\Client\TimeoutException;
use Amp\Process\Process;
use Amp\Process\ProcessException;
use Bref\Cli\BrefCloudClient;
use Bref\Cli\Cli\IO;
use Bref\Cli\Cli\Styles;
use Bref\Cli\Components\ServerlessFramework;
use Bref\Cli\Helpers\DependencyAnalyzer;
use Exception;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Throwable;
use ZipArchive;
use function Amp\async;
use function Amp\ByteStream\buffer;
use function Amp\delay;
use function Amp\Future\await;

class Deploy extends ApplicationCommand
{
    protected function configure(): void
    {
        ini_set('memory_limit', '512M');

        $this
            ->setName('deploy')
            ->setDescription('Deploy the application')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Force the deployment');
        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        IO::writeln([Styles::brefHeader(), '']);

        [
            'appName' => $appName,
            'environmentName' => $environment,
            'config' => $config,
        ] = $this->parseStandardOptions($input);

        $brefCloud = new BrefCloudClient;

        IO::writeln([
            sprintf("Deploying %s to environment %s", Styles::bold($appName), Styles::bold($environment)),
        ]);

        $dependencyWarnings = DependencyAnalyzer::analyzeComposerDependencies();
        if (!empty($dependencyWarnings)) {
            IO::writeln('');
            foreach ($dependencyWarnings as $warning) {
                IO::warning($warning);
            }
            IO::writeln('');
        }

        IO::spin('creating deployment');

        // Retrieve the current git ref and commit message to serve as a label for the deployment
        [$gitRef, $gitMessage] = $this->getGitDetails();

        try {
            $deployment = $brefCloud->createDeployment($environment, $config, $gitRef, $gitMessage);
        } catch (ClientExceptionInterface $e) {
            // 4xx error
            $response = $e->getResponse();
            if ($response->getStatusCode() === 400) {
                $body = $response->toArray(false);
                if (($body['code'] ?? '') === 'no_aws_account') {
                    IO::spinError();
                    throw new Exception($body['message']);
                }
                if ($body['selectAwsAccount'] ?? false) {
                    IO::spinClear();
                    IO::writeln(['', "Environment $appName/$environment does not exist and will be created."]);
                    $awsAccountName = $this->selectAwsAccount($body['selectAwsAccount']);
                    IO::spin('deploying');

                    // @TODO: DEFINITELY do not like this :|
                    try {
                        $deployment = $brefCloud->createDeployment($environment, $config, $gitRef, $gitMessage, $awsAccountName);
                    } catch (ClientExceptionInterface $e) {
                        $response = $e->getResponse();
                        if ($response->getStatusCode() === 400) {
                            $body = $response->toArray(false);
                            if (($body['code'] ?? '') === 'no_region_for_environment') {
                                $region = $this->selectAwsRegion();
                                IO::spin('deploying');
                                $config['region'] = $region;
                                $deployment = $brefCloud->createDeployment($environment, $config, $gitRef, $gitMessage, $awsAccountName);
                            } else {
                                IO::spinError();
                                throw $e;
                            }
                        } else {
                            IO::spinError();
                            throw $e;
                        }
                    }
                } else {
                    IO::spinError();
                    throw $e;
                }
            } else {
                IO::spinError();
                throw $e;
            }
        }

        $deploymentId = $deployment['deploymentId'];
        $credentials = $deployment['credentials'] ?? null;

        IO::writeln("<href={$deployment['url']}>" . Styles::gray($deployment['url']) . '</>');

        $isServerlessFrameworkDeploy = $config['type'] === 'serverless-framework';

        if ($isServerlessFrameworkDeploy) {
            if ($credentials === null) {
                IO::spinError();
                throw new Exception('Internal error: Bref Cloud did not provide AWS credentials, it should have in its response. Please report this issue.');
            }

            IO::spin('deploying');
            (new ServerlessFramework())->deploy($deploymentId, $environment, $credentials, $brefCloud, $input);
        } else {
            try {
                // Upload artifacts
                if (isset($deployment['packageUrls'])) {
                    $brefCloud->pushDeploymentLogs($deploymentId, 'Packaging and uploading artifacts');

                    $this->uploadArtifacts($config, $deployment['packageUrls']);
                }

                // Start the deployment now that the artifacts are uploaded
                IO::spin('deploying');
                $brefCloud->startDeployment($deploymentId);
            } catch (Throwable $e) {
                try {
                    $brefCloud->markDeploymentFinished($deploymentId, false, 'Error: ' . $e->getMessage(), '');
                } catch (Exception $e) {
                    // If we cannot mark the deployment as finished, we still want to throw the original exception
                }
                throw $e;
            }
        }

        $startTime = time();
        $deployLogs = [];

        // Timeout after 15 minutes
        while (time() - $startTime < 15 * 60) {
            $deployment = $brefCloud->getDeployment($deploymentId);

            // Logs
            if (! $isServerlessFrameworkDeploy) {
                // Diff between all the deployment logs and the ones we already know about
                $newLogs = array_slice($deployment['logs'], count($deployLogs));
                IO::verbose(array_map(fn ($record) => $record['line'], $newLogs));
                $deployLogs = $deployment['logs'];
            }

            if ($deployment['status'] === 'success') {
                IO::spinSuccess($deployment['message'], $deployment['app_url'] ?? null);
                return 0;
            }
            if ($deployment['status'] === 'failed') {
                IO::spinError($deployment['message']);
                IO::writeln([
                    Styles::bold(Styles::red($deployment['error_message'] ?? 'Unknown error')),
                    '',
                    Styles::gray('Deployment logs: ' . $deployment['url']),
                ]);
                return 1;
            }

            delay(1);
        }

        IO::spinError('timeout');
        IO::writeln(['', Styles::gray('Deployment logs: ' . $deployment['url'])]);

        throw new Exception('Deployment timed out after 15 minutes');
    }

    /**
     * @param array{ name: string }[] $selectAwsAccount
     * @throws Exception
     */
    private function selectAwsAccount(array $selectAwsAccount): string
    {
        $awsAccountName = IO::ask(new ChoiceQuestion(
            'Please select the AWS account to deploy to:',
            array_map(fn($account) => $account['name'], $selectAwsAccount),
        ));
        if (! is_string($awsAccountName)) {
            throw new Exception('No AWS account selected');
        }
        return $awsAccountName;
    }


    private function selectAwsRegion(): string
    {
        $region = IO::ask(new ChoiceQuestion(
            'Please select the AWS region to deploy to:',
            [
                'us-east-1',
                'us-east-2',
                'eu-west-1',
                'eu-west-2',
                'eu-west-3',
                // @TODO: regions
            ],
        ));

        if (! is_string($region)) {
            throw new Exception('No AWS Region selected');
        }

        return $region;
    }

    /**
     * @return array{string, string}
     * @throws BufferException
     * @throws ProcessException
     */
    private function getGitDetails(): array
    {
        IO::verbose('Retrieving git ref and commit message');

        $gifRefProcess = Process::start('git rev-parse HEAD');
        $gitMessageProcess = Process::start('git log -1 --pretty=%B');
        // Await both processes
        $gifRefProcess->join();
        $gitMessageProcess->join();
        $gitRef = buffer($gifRefProcess->getStdout());
        $gitMessage = trim(buffer($gitMessageProcess->getStdout()));
        // Keep only the 1st line
        $gitMessage = explode("\n", $gitMessage)[0];

        IO::verbose('Git ref: ' . $gitRef);
        $gitMessageLog = explode("\n", $gitMessage)[0];
        IO::verbose(sprintf(
            'Git commit message: "%s%s"',
            substr($gitMessageLog, 0, 80),
            strlen($gitMessage) > 80 ? '…' : '',
        ));

        return [$gitRef, $gitMessage];
    }

    /**
     * @param array{name: string, team: string, type: string, packages?: mixed} $config
     * @param array<string, string> $packageUrls Map of package ID to pre-signed URL
     */
    private function uploadArtifacts(array $config, array $packageUrls): void
    {
        if (! isset($config['packages'])) return;
        if (! is_array($config['packages'])) return;
        if (empty($config['packages'])) return;

        IO::spin('packaging');

        $archivePaths = [];
        foreach ($packageUrls as $id => $url) {
            $package = $config['packages'][$id];
            $archivePaths[$id] = $this->packageArtifact($id, $package['path'], $package['patterns']);
        }

        IO::spin('uploading');

        $timeout = 120;
        $client = (new HttpClientBuilder)
            ->retry(0)
            ->intercept(new SetRequestHeader('User-Agent', 'Bref CLI'))
            ->intercept(new SetRequestHeader('Content-Type', 'application/json'))
            ->intercept(new SetRequestHeader('Accept', 'application/json'))
            ->intercept(new SetRequestTimeout(10, 10, $timeout, 60))
            ->build();

        $promises = [];
        foreach ($archivePaths as $id => $archivePath) {
            $url = $packageUrls[$id];

            IO::verbose(sprintf(
                'Uploading %s (%d MB)',
                $archivePath,
                round(((float) filesize($archivePath)) / 1024. / 1024., 1)
            ));

            $request = new Request($url, 'PUT', StreamedContent::fromFile($archivePath));
            $promises[] = async(fn() => $client->request($request));
        }

        try {
            await($promises);
        } catch (TimeoutException) {
            throw new Exception("Timeout while uploading packages after $timeout seconds. This is likely due to a slow network connection");
        } catch (Exception $e) {
            throw new Exception('Error while uploading packages: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * @param string[] $patterns
     */
    private function packageArtifact(string $id, string $path, array $patterns): string
    {
        if (! is_dir('.bref') && ! mkdir('.bref') && ! is_dir('.bref')) {
            throw new Exception(sprintf('Directory "%s" could not be created', '.bref'));
        }

        // Turn the package patterns into regexes
        $patternRegexes = [];
        foreach ($patterns as $pattern) {
            $include = ! str_starts_with($pattern, '!');
            $pattern = ltrim($pattern, '!');
            // Prepend the root path
            $pattern = $path . DIRECTORY_SEPARATOR . $pattern;
            // Turn the pattern into a regex
            $pattern = str_replace(['\\', '/', '.', '**', '*'], ['\\\\', '\/', '\.', '.+', '[^\/\\\\]+'], $pattern);
            $regex = "/^$pattern$/";
            $patternRegexes[$regex] = $include;
        }

        $archivePath = ".bref/package-$id.zip";

        $zip = new ZipArchive;
        $zip->open($archivePath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $this->addFolderToArchive($zip, $path, $patternRegexes, $path);
        IO::verbose("Writing $archivePath");
        $zip->close();

        return $archivePath;
    }

    /**
     * @param array<string, bool> $patternRegexes
     */
    private function addFolderToArchive(ZipArchive $zip, string $path, array $patternRegexes, string $trimFromPath): void
    {
        $list = scandir($path);
        if ($list === false) {
            throw new Exception('Could not read directory: ' . $path);
        }

        foreach ($list as $filename) {
            if (in_array($filename, ['.', '..'])) continue;

            $filepath = $path . DIRECTORY_SEPARATOR . $filename;

            // Apply the patterns
            // The last pattern that matches wins, so we start from the end
            foreach (array_reverse($patternRegexes) as $regex => $shouldInclude) {
                try {
                    $match = preg_match($regex, $filepath);
                } catch (Exception $e) {
                    throw new Exception("Invalid packaging pattern regex: $regex (path: $filepath)", 0, $e);
                }
                if ($match) {
                    if (! $shouldInclude) {
                        continue 2;
                    }
                    // Get out of the loop because this file needs to be included
                    break;
                }
            }

            if (is_dir($filepath)) {
                $this->addFolderToArchive($zip, $filepath, $patternRegexes, $trimFromPath);
            } elseif (is_file($filepath)) {
                $zip->addFile($filepath, str_replace($trimFromPath, '', $filepath));
                delay(0); // Yield to the event loop
            } else {
                throw new Exception('Unsupported file type: ' . $filepath);
            }
        }
    }
}
