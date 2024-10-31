<?php declare(strict_types=1);

namespace Bref\Cli\Commands;

use Amp\ByteStream\BufferException;
use Amp\Process\Process;
use Amp\Process\ProcessException;
use Bref\Cli\BrefCloudClient;
use Bref\Cli\Cli\IO;
use Bref\Cli\Cli\Styles;
use Bref\Cli\Components\ServerlessFramework;
use Bref\Cli\Config;
use Exception;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use function Amp\ByteStream\buffer;
use function Amp\delay;

class Deploy extends Command
{
    protected function configure(): void
    {
        ini_set('memory_limit', '512M');

        $this
            ->setName('deploy')
            ->setDescription('Deploy the application')
            ->addOption('env', 'e', InputOption::VALUE_REQUIRED, 'The environment to deploy to', 'dev')
            ->addOption('directory', 'd', InputOption::VALUE_OPTIONAL, 'The directory to deploy', getcwd())
            ->addOption('force', null, InputOption::VALUE_NONE, 'Force the deployment');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        IO::writeln([Styles::brefHeader(), '']);

        $brefCloud = new BrefCloudClient;

        /** @var string $environment */
        $environment = $input->getOption('env');

        /** @var string $dir */
        $dir = $input->getOption('directory');

        $config = Config::loadConfig($brefCloud, $dir);

        $appName = $config['name'];

        IO::writeln([
            sprintf("Deploying %s to environment %s", Styles::bold($appName), Styles::bold($environment)),
            '',
        ]);

        IO::spin('deploying');

        // Retrieve the current git ref and commit message to serve as a label for the deployment
        [$gitRef, $gitMessage] = $this->getGitDetails();

        try {
            $deployment = $brefCloud->startDeployment($environment, $config, $gitRef, $gitMessage);
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
                        $deployment = $brefCloud->startDeployment($environment, $config, $gitRef, $gitMessage, $awsAccountName);
                    } catch (ClientExceptionInterface $e) {
                        $response = $e->getResponse();
                        if ($response->getStatusCode() === 400) {
                            $body = $response->toArray(false);
                            if (($body['code'] ?? '') === 'no_region_for_environment') {
                                $region = $this->selectAwsRegion();
                                IO::spin('deploying');
                                $config['region'] = $region;
                                $deployment = $brefCloud->startDeployment($environment, $config, $gitRef, $gitMessage, $awsAccountName, $region);
                            }
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

        if ($config['type'] === 'serverless-framework') {
            if ($credentials === null) {
                IO::spinError();
                throw new Exception('Internal error: Bref Cloud did not provide AWS credentials, it should have in its response. Please report this issue.');
            }

            $component = new ServerlessFramework();
            $component->deploy($deploymentId, $environment, $credentials, $brefCloud, $input);
        }

        $startTime = time();

        // Timeout after 10 minutes
        while (time() - $startTime < 600) {
            $deployment = $brefCloud->getDeployment($deploymentId);
            if ($deployment['status'] === 'success') {
                IO::spinSuccess($deployment['message']);
                if ($deployment['outputs'] ?? null) {
                    IO::writeln('');
                    foreach ($deployment['outputs'] as $key => $value) {
                        IO::writeln("$key: $value");
                    }
                }
                return 0;
            }
            if ($deployment['status'] === 'failed') {
                IO::spinError($deployment['message']);
                IO::writeln(['', Styles::gray('Deployment logs: ' . $deployment['url'])]);
                return 1;
            }
            delay(1);
        }

        IO::spinError('timeout');
        IO::writeln(['', Styles::gray('Deployment logs: ' . $deployment['url'])]);

        throw new Exception('Deployment timed out after 10 minutes');
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
            ]
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
        IO::verbose('Git message: ' . $gitMessage);

        return [$gitRef, $gitMessage];
    }
}
