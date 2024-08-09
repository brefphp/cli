<?php declare(strict_types=1);

namespace Bref\Cli\Commands;

use Bref\Cli\BrefCloudClient;
use Bref\Cli\Components\ServerlessFramework;
use Bref\Cli\Config;
use Bref\Cli\Helpers\BrefSpinner;
use Bref\Cli\Helpers\Styles;
use Exception;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;

class Deploy extends Command
{
    protected function configure(): void
    {
        $this
            ->setName('deploy')
            ->setDescription('Deploy the application')
            ->addOption('env', 'e', InputOption::VALUE_REQUIRED, 'The environment to deploy to', 'dev');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln([Styles::brefHeader(), '']);

        $environment = (string) $input->getOption('env');
        $config = Config::loadConfig();
        $appName = $config['name'];

        $output->writeln(["Deploying $appName to environment $environment", '']);

        $progress = new BrefSpinner($output);
        $progress->start('deploying');

        // Upload artifacts
        // ...

        $progress->advance();

        // Retrieve the current git ref and commit message to serve as a label for the deployment
        $gitRef = exec('git rev-parse HEAD') ?: null;
        $gitMessage = exec('git log -1 --pretty=%B') ?: '';
        // Keep only the 1st line
        $gitMessage = explode("\n", $gitMessage)[0];

        $progress->advance();

        $brefCloud = new BrefCloudClient;
        try {
            $deployment = $brefCloud->startDeployment($environment, $config, $gitRef, $gitMessage);
        } catch (ClientExceptionInterface $e) {
            // 4xx error
            $response = $e->getResponse();
            if ($response->getStatusCode() === 400) {
                $body = $response->toArray(false);
                if (($body['code'] ?? '') === 'no_aws_account') {
                    $progress->finish('error');
                    throw new Exception($body['message']);
                }
                if ($body['selectAwsAccount'] ?? false) {
                    $progress->finish('paused');
                    $output->writeln(['', "Environment $appName/$environment does not exist and will be created."]);
                    $awsAccountName = $this->selectAwsAccount($body['selectAwsAccount'], $input, $output);
                    $progress->start('deploying');
                    $deployment = $brefCloud->startDeployment($environment, $config, $gitRef, $gitMessage, $awsAccountName);
                } else {
                    $progress->finish('error');
                    throw $e;
                }
            } else {
                $progress->finish('error');
                throw $e;
            }
        }

        $progress->advance();

        $deploymentId = $deployment['deploymentId'];
        $message = $deployment['message'];
        $credentials = $deployment['credentials'] ?? null;

        $output->writeln("<href={$deployment['url']}>" . Styles::gray($deployment['url']) . '</>');

        if ($config['type'] === 'serverless-framework') {
            if ($credentials === null) {
                $progress->finish('error');
                throw new Exception('Internal error: Bref Cloud did not provide AWS credentials, it should have in its response. Please report this issue.');
            }

            $component = new ServerlessFramework();
            $component->deploy($deploymentId, $environment, $credentials, $output, $progress, $brefCloud);
        }

        $startTime = time();

        // Timeout after 10 minutes
        while (time() - $startTime < 600) {
            $progress->advance();

            $deployment = $brefCloud->getDeployment($deploymentId);
            if ($deployment['status'] === 'success') {
                $output->writeln(['', Styles::gray('Deployment logs: ' . $deployment['url'])]);
                $progress->finish($deployment['message']);
                if ($deployment['outputs'] ?? null) {
                    $output->writeln('');
                    foreach ($deployment['outputs'] as $key => $value) {
                        $output->writeln("$key: $value");
                    }
                }
                return 0;
            }
            if ($deployment['status'] === 'failed') {
                $progress->finish($deployment['message']);
                $output->writeln(['', Styles::gray('Deployment logs: ' . $deployment['url'])]);
                return 1;
            }
            sleep(1);
        }

        $progress->finish('timeout');
        $output->writeln(['', Styles::gray('Deployment logs: ' . $deployment['url'])]);
        throw new Exception('Deployment timed out after 10 minutes');
    }

    /**
     * @param array{ name: string }[] $selectAwsAccount
     * @throws Exception
     */
    private function selectAwsAccount(array $selectAwsAccount, InputInterface $input, OutputInterface $output): string
    {
        $question = new ChoiceQuestion(
            'Please select the AWS account to deploy to:',
            array_map(fn($account) => $account['name'], $selectAwsAccount),
        );
        /** @var QuestionHelper $helper */
        $helper = $this->getHelper('question');
        $awsAccountName = $helper->ask($input, $output, $question);
        if (! is_string($awsAccountName)) {
            throw new Exception('No AWS account selected');
        }
        return $awsAccountName;
    }
}