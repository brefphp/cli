<?php declare(strict_types=1);

namespace Bref\Cli\Test\Commands;

use Aws\Command;
use Aws\Exception\AwsException;
use Bref\Cli\Commands\Connect;
use PHPUnit\Framework\TestCase;

class ConnectTest extends TestCase
{
    public function test_a_denial_by_a_service_control_policy_explains_aws_projects(): void
    {
        $awsError = $this->awsError('User: arn:aws:sts::123456789012:assumed-role/AccountFullAccessRole/abc is not authorized to perform: cloudformation:DescribeStacks on resource: arn:aws:cloudformation:us-east-1:123456789012:stack/bref-cloud-connect/* with an explicit deny in a service control policy: arn:aws:organizations::111111111111:policy/o-abc/service_control_policy/p-abc');

        $e = Connect::explainDeployError($awsError, 'bref-cloud-connect', 'us-east-1');

        $this->assertStringStartsWith("AWS denied the deployment of the 'bref-cloud-connect' CloudFormation stack in us-east-1: User: arn:aws:sts::123456789012:assumed-role/AccountFullAccessRole/abc is not authorized", $e->getMessage());
        $this->assertStringContainsString('"Sign up for AWS (new)", it is an AWS project', $e->getMessage());
        $this->assertStringContainsString('https://bref.sh/docs/setup#aws-projects', $e->getMessage());
        $this->assertSame($awsError, $e->getPrevious());
    }

    public function test_other_aws_errors_are_left_unchanged(): void
    {
        $awsError = $this->awsError('Stack with id bref-cloud-connect does not exist');

        $this->assertSame($awsError, Connect::explainDeployError($awsError, 'bref-cloud-connect', 'us-east-1'));
    }

    private function awsError(string $awsMessage): AwsException
    {
        return new AwsException('Error executing "DescribeStacks"; AWS HTTP error: ' . $awsMessage, new Command('DescribeStacks'), [
            'code' => 'AccessDenied',
            'message' => $awsMessage,
        ]);
    }
}
