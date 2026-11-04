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
    public function __construct(
        private readonly ?BrefCloudClient $brefCloud = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('secret:create')
            ->setDescription('Create a secret for an environment')
            ->addArgument('name', InputArgument::OPTIONAL, 'The secret name')
            ->addArgument('value', InputArgument::OPTIONAL, 'The secret value')
            ->addOption('app', null, InputOption::VALUE_REQUIRED, 'The app name (if no config file exists)')
            ->addOption('aws-account', null, InputOption::VALUE_REQUIRED, 'The name of the AWS account in Bref Cloud, if the environment is not deployed yet (default: the team\'s only AWS account)')
            ->addOption('region', null, InputOption::VALUE_REQUIRED, 'The AWS region, if the environment is not deployed yet (default: the region in the config file)');

        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        [
            'appName' => $appName,
            'environmentName' => $environment,
            'team' => $team,
            'region' => $configRegion,
        ] = $this->parseEnvironmentOptions($input);

        // Get secret name (from argument or prompt)
        $name = $input->getArgument('name');
        if (empty($name)) {
            $question = new Question('Secret name: ');
            $question->setValidator(function (mixed $value): string {
                if (! is_string($value) || $value === '') {
                    throw new Exception('Secret name cannot be empty');
                }
                return $value;
            });
            $name = IO::ask($question);
        }
        if (! is_string($name)) {
            throw new Exception('Secret name cannot be empty');
        }

        // Get secret value (from argument or prompt)
        $value = $input->getArgument('value');
        if ($value === null) {
            $question = new Question('Secret value: ');
            $question->setHidden(true)->setHiddenFallback(false);
            $value = IO::ask($question);
        }
        if (! is_string($value)) {
            throw new Exception('Secret value cannot be empty');
        }

        $brefCloud = $this->brefCloud ?? new BrefCloudClient;

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
                $this->warnAboutIgnoredOptions($input, $brefCloud, $awsAccountId, $region);
            }
        } catch (HttpExceptionInterface $e) {
            if ($e->getResponse()->getStatusCode() !== 404) {
                throw $e;
            }
            IO::verbose('Environment not found: ' . $e->getMessage());
            // Environment doesn't exist
        }

        if ($awsAccountId === null) {
            // The environment doesn't exist or isn't deployed: the secret is created in the AWS account and region it will be deployed to
            $awsAccountId = $this->selectAwsAccount($input, $brefCloud, $teamId);
            $region = $this->selectRegion($input, $configRegion);
        }

        IO::spin('Creating secret');
        $brefCloud->createSecret($teamId, $appName, $environment, $name, $value, $awsAccountId, $region);
        IO::spinSuccess('Secret created');

        return 0;
    }

    /**
     * The secret is created where the environment is deployed: warn if the options point elsewhere.
     */
    private function warnAboutIgnoredOptions(InputInterface $input, BrefCloudClient $brefCloud, ?int $awsAccountId, string $region): void
    {
        $regionOption = $input->getOption('region');
        if (is_string($regionOption) && $regionOption !== '' && $regionOption !== $region) {
            IO::warning("The environment is deployed in $region: the --region option is ignored, the secret is created in $region");
        }

        $awsAccountOption = $input->getOption('aws-account');
        if (is_string($awsAccountOption) && $awsAccountOption !== '') {
            $awsAccountName = null;
            foreach ($brefCloud->listAwsAccounts() as $account) {
                if ($account['id'] === $awsAccountId) {
                    $awsAccountName = $account['name'];
                }
            }
            if ($awsAccountName !== $awsAccountOption) {
                IO::warning("The environment is deployed in the AWS account '$awsAccountName': the --aws-account option is ignored, the secret is created in that account");
            }
        }
    }

    private function selectAwsAccount(InputInterface $input, BrefCloudClient $brefCloud, int $teamId): int
    {
        // The API returns the AWS accounts of all the user's teams
        $awsAccountsByName = [];
        foreach ($brefCloud->listAwsAccounts() as $account) {
            if ($account['team_id'] === $teamId) {
                $awsAccountsByName[$account['name']] = $account['id'];
            }
        }
        if (empty($awsAccountsByName)) {
            throw new Exception('No AWS accounts found. Please connect an AWS account first using "bref connect".');
        }

        $awsAccountName = $input->getOption('aws-account');
        if (is_string($awsAccountName) && $awsAccountName !== '') {
            if (! isset($awsAccountsByName[$awsAccountName])) {
                throw new Exception("AWS account '$awsAccountName' not found in the team, the AWS accounts are: " . implode(', ', array_keys($awsAccountsByName)));
            }
            return $awsAccountsByName[$awsAccountName];
        }
        if (count($awsAccountsByName) === 1) {
            return reset($awsAccountsByName);
        }
        if (! $input->isInteractive()) {
            throw new Exception('The environment is not deployed yet and the team has several AWS accounts: set the AWS account to use with the --aws-account option (' . implode(', ', array_keys($awsAccountsByName)) . ')');
        }

        $awsAccountName = IO::ask(new ChoiceQuestion(
            'Select the AWS account:',
            array_keys($awsAccountsByName),
        ));
        if (! is_string($awsAccountName)) {
            throw new Exception('No AWS account selected');
        }
        return $awsAccountsByName[$awsAccountName];
    }

    private function selectRegion(InputInterface $input, ?string $configRegion): string
    {
        $region = $input->getOption('region');
        if (is_string($region) && $region !== '') {
            return $region;
        }
        if ($configRegion) {
            return $configRegion;
        }
        if (! $input->isInteractive()) {
            throw new Exception('The environment is not deployed yet: set the AWS region with the --region option');
        }

        $region = IO::ask(new ChoiceQuestion(
            'Select the AWS region:',
            BrefCloudClient::AWS_REGIONS,
        ));
        if (! is_string($region)) {
            throw new Exception('No region selected');
        }
        return $region;
    }
}
