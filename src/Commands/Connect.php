<?php declare(strict_types=1);

namespace Bref\Cli\Commands;

use AsyncAws\Core\Sts\StsClient;
use Aws\CloudFormation\CloudFormationClient;
use Bref\Cli\BrefCloudClient;
use Bref\Cli\Helpers\BrefSpinner;
use Bref\Cli\Helpers\CloudFormation;
use Bref\Cli\Helpers\Styles;
use Exception;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
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
            ->addOption('profile', null, InputOption::VALUE_REQUIRED, 'The AWS profile to use', 'default');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln([
            Styles::brefHeader(),
            '',
            'Retrieving information...',
        ]);

        // @phpstan-ignore-next-line
        $awsProfile = (string) $input->getOption('profile');

        $accountId = $this->getCurrentAwsAccountId($awsProfile);
        // TODO verbose only
        $output->writeln('Current AWS account ID: ' . $accountId);

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
            $teamId = $this->selectTeam($brefCloud, $input, $output);
        }

        $details = $brefCloud->prepareConnectAwsAccount($teamId);

        if ($isConnected) {
            $output->writeln([
                'This AWS account is already connected to Bref Cloud.',
                '',
                'The connection will be refreshed now.',
            ]);
        } else {
            $output->writeln([
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

            /** @var QuestionHelper $helper */
            $helper = $this->getHelper('question');
            $question = new Question('Display name:');
            $question->setValidator(function (string $answer) use ($existingAccounts): string {
                // Check if the name is already taken
                foreach ($existingAccounts as $account) {
                    if ($account['name'] === $answer) {
                        throw new Exception('This account name is already used in your team');
                    }
                }
                return $answer;
            });
            $accountName = $helper->ask($input, $output, $question);
            if (! is_string($accountName)) {
                throw new Exception('No account name provided');
            }
        }

        $progress = new BrefSpinner($output);
        $progress->start('connecting');

        $cloudFormationClient = new CloudFormationClient([
            'profile' => $awsProfile,
            'region' => $details['region'],
        ]);
        // TODO verbose
        $output->writeln(['Deploying CloudFormation stack', '']);
        $stackParameters = [
            'BrefCloudAccountId' => $details['bref_cloud_account_id'],
            'UniqueExternalId' => $details['unique_external_id'],
        ];
        if ($details['role_name']) {
            $stackParameters['RoleName'] = $details['role_name'];
        }
        $cloudFormation = new CloudFormation($cloudFormationClient);
        $cloudFormation->deploy(
            $details['stack_name'],
            $details['template_url'],
            $stackParameters,
        );

        if (!$isConnected && $accountName) {
            $progress->setMessage('adding to Bref Cloud');

            $stackOutputs = $cloudFormation->getStackOutputs($details['stack_name']);
            $roleArn = $stackOutputs['BrefCloudRoleArn'];
            $brefCloud->addAwsAccount($teamId, $accountName, $roleArn);
        }

        $progress->finish('connected');

        $output->writeln([
            '',
            'The AWS account is now connected to Bref Cloud 🎉',
        ]);

        return 0;
    }

    private function getCurrentAwsAccountId(string $profile): string
    {
        $sts = new StsClient([
            'profile' => $profile,
        ]);
        $accountId = $sts->getCallerIdentity()->getAccount();
        if (! $accountId) {
            throw new RuntimeException('Could not determine the AWS account ID');
        }
        return $accountId;
    }

    private function selectTeam(BrefCloudClient $brefCloud, InputInterface $input, OutputInterface $output): int
    {
        $teams = $brefCloud->listTeams();
        if (count($teams) === 0) {
            throw new Exception('Your Bref Cloud account has no team configured. Please create a team via the web UI first and retry.');
        }
        if (count($teams) === 1) {
            return $teams[0]['id'];
        }
        /** @var QuestionHelper $helper */
        $helper = $this->getHelper('question');
        $question = new ChoiceQuestion(
            'You have access to multiple teams in Bref Cloud. Please select which one to use:',
            array_map(fn($team) => $team['name'], $teams),
        );
        $teamName = $helper->ask($input, $output, $question);
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