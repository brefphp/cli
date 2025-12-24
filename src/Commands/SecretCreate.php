<?php declare(strict_types=1);

namespace Bref\Cli\Commands;

use Bref\Cli\BrefCloudClient;
use Bref\Cli\Cli\IO;
use Exception;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Question\Question;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;

class SecretCreate extends ApplicationCommand
{
    protected function configure(): void
    {
        $this
            ->setName('secret:create')
            ->setDescription('Create a secret for an environment')
            ->addArgument('name', InputArgument::OPTIONAL, 'The secret name')
            ->addArgument('value', InputArgument::OPTIONAL, 'The secret value')
            ->addOption('app', null, InputOption::VALUE_REQUIRED, 'The app name (if no config file exists)');

        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // If --app and --team are provided, we can skip loading the config file
        if ($input->getOption('app') && $input->getOption('team')) {
            $appName = $input->getOption('app');
            $team = $input->getOption('team');
            $environment = $input->getOption('env');
        } else {
            [
                'appName' => $appName,
                'environmentName' => $environment,
                'team' => $team,
            ] = $this->parseStandardOptions($input);

            // Override app name if --app option provided
            if ($input->getOption('app')) {
                $appName = $input->getOption('app');
            }
        }

        // Get secret name (from argument or prompt)
        $name = $input->getArgument('name');
        if (empty($name)) {
            $question = new Question('Secret name: ');
            $question->setValidator(function (?string $value): string {
                if (empty($value)) {
                    throw new Exception('Secret name cannot be empty');
                }
                return $value;
            });
            $name = IO::ask($question);
        }

        // Get secret value (from argument or prompt)
        $value = $input->getArgument('value');
        if ($value === null) {
            $question = new Question('Secret value: ');
            $question->setHidden(true)->setHiddenFallback(false);
            $value = IO::ask($question);
        }

        $brefCloud = new BrefCloudClient;

        // Resolve team slug to team ID
        $teams = $brefCloud->listTeams();
        $teamId = null;
        foreach ($teams as $t) {
            if ($t['slug'] === $team) {
                $teamId = $t['id'];
                break;
            }
        }
        if ($teamId === null) {
            throw new Exception("Team '$team' not found");
        }

        // Check if the environment exists and is deployed
        $awsAccountId = null;
        $region = null;
        try {
            $existingEnv = $brefCloud->findEnvironment($team, $appName, $environment);
            if (! empty($existingEnv['region'])) {
                // Environment is deployed, use its aws_account_id and region
                $awsAccountId = $existingEnv['aws_account_id'];
                $region = $existingEnv['region'];
            }
        } catch (HttpExceptionInterface $e) {
            if ($e->getResponse()->getStatusCode() !== 404) {
                throw $e;
            }
            IO::verbose('Environment not found: ' . $e->getMessage());
            // Environment doesn't exist
        }

        if ($awsAccountId === null) {
            // Environment doesn't exist or isn't deployed, need to select AWS account and region
            $awsAccounts = $brefCloud->listAwsAccounts();
            if (empty($awsAccounts)) {
                throw new Exception('No AWS accounts found. Please connect an AWS account first using "bref connect".');
            }
            $awsAccountsByName = [];
            foreach ($awsAccounts as $account) {
                $awsAccountsByName[$account['name']] = $account['id'];
            }
            $awsAccountName = IO::ask(new ChoiceQuestion(
                'Select the AWS account:',
                array_keys($awsAccountsByName),
            ));
            if (! is_string($awsAccountName)) {
                throw new Exception('No AWS account selected');
            }
            $awsAccountId = $awsAccountsByName[$awsAccountName];

            $region = IO::ask(new ChoiceQuestion(
                'Select the AWS region:',
                BrefCloudClient::AWS_REGIONS,
            ));
            if (! is_string($region)) {
                throw new Exception('No region selected');
            }
        }

        IO::spin('Creating secret');
        $brefCloud->createSecret($teamId, $appName, $environment, $name, $value, $awsAccountId, $region);
        IO::spinSuccess('Secret created');

        return 0;
    }
}
