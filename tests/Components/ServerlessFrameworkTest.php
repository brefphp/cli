<?php declare(strict_types=1);

namespace Bref\Cli\Test\Components;

use Bref\Cli\Commands\Deploy;
use Bref\Cli\Components\ServerlessFramework;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;

class ServerlessFrameworkTest extends TestCase
{
    public function test_forwards_the_config_file_and_the_params_to_serverless(): void
    {
        $input = new ArrayInput([
            '--config' => 'serverless.prod.yml',
            '--param' => ['layer=arn:aws:lambda:us-east-1:123:layer:php-85:1', 'domain=example.com'],
        ], (new Deploy)->getDefinition());

        $this->assertSame([
            '--config', 'serverless.prod.yml',
            '--param', 'layer=arn:aws:lambda:us-east-1:123:layer:php-85:1',
            '--param', 'domain=example.com',
        ], (new ServerlessFramework)->commonOptions($input));
    }

    public function test_no_options_by_default(): void
    {
        $input = new ArrayInput([], (new Deploy)->getDefinition());

        $this->assertSame([], (new ServerlessFramework)->commonOptions($input));
    }

    /**
     * @param array{string, string}|null $expected
     */
    #[DataProvider('failedDeployments')]
    public function test_finds_the_stack_that_a_failed_deployment_created(string $output, ?array $expected): void
    {
        $this->assertSame($expected, (new ServerlessFramework)->findCreatedStack($output));
    }

    /**
     * @return array<string, array{string, array{string, string}|null}>
     */
    public static function failedDeployments(): array
    {
        return [
            'first deployment, failed in CloudFormation' => [
                <<<'OUTPUT'
                Deploying night-deploy-hints to stage after (eu-west-3)

                Excluding development dependencies for service package
                CREATE_IN_PROGRESS - AWS::CloudFormation::Stack - night-deploy-hints-after
                  CREATE_IN_PROGRESS - AWS::S3::Bucket - ServerlessDeploymentBucket
                  CREATE_IN_PROGRESS - AWS::S3::Bucket - ServerlessDeploymentBucket
                CREATE_COMPLETE - AWS::S3::Bucket - ServerlessDeploymentBucket
                  CREATE_IN_PROGRESS - AWS::S3::BucketPolicy - ServerlessDeploymentBucketPolicy
                  CREATE_COMPLETE - AWS::S3::BucketPolicy - ServerlessDeploymentBucketPolicy
                  CREATE_COMPLETE - AWS::CloudFormation::Stack - night-deploy-hints-after
                Uploading CloudFormation file to S3
                Uploading State file to S3
                Uploading service night-deploy-hints.zip file to S3 (1.18 MB)
                UPDATE_IN_PROGRESS - AWS::CloudFormation::Stack - night-deploy-hints-after
                  CREATE_IN_PROGRESS - AWS::Lambda::Function - HelloLambdaFunction
                CREATE_FAILED - AWS::Lambda::Function - HelloLambdaFunction
                  UPDATE_ROLLBACK_IN_PROGRESS - AWS::CloudFormation::Stack - night-deploy-hints-after
                  UPDATE_ROLLBACK_COMPLETE - AWS::CloudFormation::Stack - night-deploy-hints-after

                × Stack night-deploy-hints-after failed to deploy (469s)
                Environment: darwin, node 26.0.0, framework 3.61.0
                Credentials: Local, environment variables
                Docs:        github.com/oss-serverless/serverless

                Error:
                CREATE_FAILED: HelloLambdaFunction (AWS::Lambda::Function)
                Resource handler returned message: "Function code combined with layers exceeds the maximum allowed size of 262144000 bytes."

                OUTPUT,
                ['night-deploy-hints-after', 'eu-west-3'],
            ],
            // e.g. deployed with the Serverless Framework before moving to Bref Cloud
            'the stack already existed, a nested stack is created' => [
                <<<'OUTPUT'
                Deploying night-deploy-hints to stage after (eu-west-3)

                Excluding development dependencies for service package
                Uploading CloudFormation file to S3
                Uploading State file to S3
                Uploading service night-deploy-hints.zip file to S3 (1.18 MB)
                UPDATE_IN_PROGRESS - AWS::CloudFormation::Stack - night-deploy-hints-after
                  CREATE_IN_PROGRESS - AWS::CloudFormation::Stack - PermissionsNestedStack
                  CREATE_IN_PROGRESS - AWS::Lambda::Function - HelloLambdaFunction
                CREATE_FAILED - AWS::Lambda::Function - HelloLambdaFunction
                  UPDATE_ROLLBACK_IN_PROGRESS - AWS::CloudFormation::Stack - night-deploy-hints-after
                  UPDATE_ROLLBACK_COMPLETE - AWS::CloudFormation::Stack - night-deploy-hints-after

                × Stack night-deploy-hints-after failed to deploy (95s)

                OUTPUT,
                null,
            ],
            'failed before CloudFormation' => [
                <<<'OUTPUT'
                Environment: darwin, node 26.0.0, framework 3.61.0
                Docs:        github.com/oss-serverless/serverless

                Error:
                Cannot resolve serverless.yml: Variables resolution errored with:
                  - Cannot resolve variable at "provider.environment.API_KEY": Value not found at "env" source

                OUTPUT,
                null,
            ],
        ];
    }
}
