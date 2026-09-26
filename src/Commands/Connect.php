<?php declare(strict_types=1);

namespace Bref\Cli\Commands;

use Aws\CloudFormation\CloudFormationClient;
use Aws\Exception\AwsException;
use Aws\Exception\CredentialsException;
use Aws\Sts\StsClient;
use Bref\Cli\BrefCloudClient;
use Bref\Cli\Cli\IO;
use Bref\Cli\Cli\Styles;
use Bref\Cli\Helpers\CloudFormation;
use Exception;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Question\Question;

class Connect extends Command
{
    protected function configure(): void
    {
        $this
            ->setName('connect')
            ->setDescription('Connect an AWS account to Bref Cloud using the AWS credentials configured on your machine')
            ->addOption('profile', null, InputOption::VALUE_REQUIRED, 'The AWS profile to use (defaults to the AWS_PROFILE environment variable, then to "default")');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        IO::writeln([
            Styles::brefHeader(),
            '',
            'Retrieving information...',
        ]);

        /** @var string|null $awsProfile */
        $awsProfile = $input->getOption('profile');
        if ($awsProfile !== null) {
            putenv('AWS_PROFILE=' . $awsProfile);
        } else {
            // Keep the profile selected with `export AWS_PROFILE=...`, like the AWS CLI
            $awsProfile = getenv('AWS_PROFILE') ?: 'default';
        }

        $accountId = $this->getCurrentAwsAccountId($awsProfile);
        // TODO verbose only
        IO::writeln('Current AWS account ID: ' . $accountId);

        $brefCloud = new BrefCloudClient;
        $existingAccounts = $brefCloud->listAwsAccounts();

        // Check if the account is already connected
        $isConnected = false;
        $teamId = null;
        foreach ($existingAccounts as $account) {
            if (str_contains($account['role_arn'], $accountId)) {
                $isConnected = true;
                $teamId = $account['team_id'];
                break;
            }
        }

        if (! $teamId) {
            $teamId = $this->selectTeam($brefCloud);
        }

        $details = $brefCloud->prepareConnectAwsAccount($teamId);

        if ($isConnected) {
            IO::writeln([
                'This AWS account is already connected to Bref Cloud.',
                '',
                'The connection will be refreshed now.',
            ]);
        } else {
            IO::writeln([
                'Connecting Bref Cloud to your AWS account...',
                '',
                Styles::gray("This will create a CloudFormation stack named '{$details['stack_name']}' in your AWS account. This stack contains an IAM role that allows Bref Cloud to access your account. This is the recommended method to connect SaaS services to AWS accounts 👌"),
                '',
                Styles::gray("If you want to review the IAM role: {$details['template_url']}"),
                '',
                "ID of the AWS account that will be connected: $accountId",
                '',
                'Please name this AWS account in Bref Cloud.',
            ]);

            $question = new Question('Display name: ');
            $question->setValidator(function (mixed $answer) use ($existingAccounts): string {
                if (! is_string($answer) || $answer === '') {
                    throw new Exception('Account name cannot be empty');
                }
                // Check if the name is already taken
                foreach ($existingAccounts as $account) {
                    if ($account['name'] === $answer) {
                        throw new Exception('This account name is already used in your team');
                    }
                }
                return $answer;
            });
            $accountName = IO::ask($question);
            if (! is_string($accountName)) {
                throw new Exception('No account name provided');
            }
        }

        IO::spin('connecting');

        $cloudFormationClient = new CloudFormationClient([
            'region' => $details['region'],
        ]);
        IO::verbose(['Deploying CloudFormation stack']);
        $stackParameters = [
            'BrefCloudAccountId' => $details['bref_cloud_account_id'],
            'UniqueExternalId' => $details['unique_external_id'],
        ];
        if ($details['role_name']) {
            $stackParameters['RoleName'] = $details['role_name'];
        }
        $cloudFormation = new CloudFormation($cloudFormationClient);
        try {
            $cloudFormation->deploy(
                $details['stack_name'],
                $details['template_url'],
                $stackParameters,
            );
        } catch (AwsException $e) {
            throw self::explainDeployError($e, $details['stack_name'], $details['region']);
        }

        if (!$isConnected && $accountName) {
            IO::spin('adding to Bref Cloud');

            $stackOutputs = $cloudFormation->getStackOutputs($details['stack_name']);
            $roleArn = $stackOutputs['BrefCloudRoleArn'];
            $brefCloud->addAwsAccount($teamId, $accountName, $roleArn);
        }

        IO::spinSuccess('connected');

        IO::writeln([
            '',
            'The AWS account is now connected to Bref Cloud 🎉',
        ]);

        return 0;
    }

    /**
     * AWS accounts created with "Sign up for AWS (new)" (AWS projects) have AWS-managed service control
     * policies: they deny CloudFormation outside of the project's region, and Bref Cloud could not
     * access the account anyway. The raw AWS error does not say any of that.
     */
    public static function explainDeployError(AwsException $e, string $stackName, string $region): Exception
    {
        $awsMessage = $e->getAwsErrorMessage() ?: $e->getMessage();
        if (! str_contains($awsMessage, 'explicit deny in a service control policy')) {
            return $e;
        }

        return new Exception(
            "AWS denied the deployment of the '$stackName' CloudFormation stack in $region: $awsMessage\n\n"
            . 'If this AWS account was created with "Sign up for AWS (new)", it is an AWS project: AWS blocks Bref Cloud from connecting to AWS projects. '
            . 'Create an AWS account with "Sign up for AWS (advanced)" instead: https://bref.sh/docs/setup#aws-projects',
            previous: $e,
        );
    }

    private function getCurrentAwsAccountId(string $profile): string
    {
        $sts = new StsClient([
            'region' => 'us-east-1',
        ]);

        try {
            $identity = $sts->getCallerIdentity()->toArray();
        } catch (CredentialsException $e) {
            // The AWS SDK tries each credential provider in turn and only reports the error of the last one
            // (the EC2 instance metadata service), which hides the actual cause, e.g. an expired `aws login` session
            if (str_contains($e->getMessage(), 'instance profile metadata service')) {
                throw new Exception(
                    "No valid AWS credentials found for the AWS profile '$profile'. "
                    . "If you log in with `aws login`, run `aws login --profile $profile` again: its sessions expire after 12 hours. "
                    . 'Use the `--profile` option to select another AWS profile.',
                    previous: $e,
                );
            }
            throw $e;
        }

        return $identity['Account'] ?? throw new RuntimeException('Could not determine the AWS account ID');
    }

    private function selectTeam(BrefCloudClient $brefCloud): int
    {
        $teams = $brefCloud->listTeams();
        if (count($teams) === 0) {
            throw new Exception('Your Bref Cloud account has no team configured. Please create a team via the web UI first and retry.');
        }
        if (count($teams) === 1) {
            return $teams[0]['id'];
        }
        $teamName = IO::ask(new ChoiceQuestion(
            'You have access to multiple teams in Bref Cloud. Please select which one to use:',
            array_map(fn($team) => $team['name'], $teams),
        ));
        if (! is_string($teamName)) {
            throw new Exception('No team selected');
        }
        $mapIdToName = array_combine(array_column($teams, 'id'), array_column($teams, 'name'));
        $teamId = array_search($teamName, $mapIdToName, true);
        if (! $teamId) {
            throw new Exception('No team selected');
        }
        return $teamId;
    }
}
