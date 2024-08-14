<?php declare(strict_types=1);

namespace Bref\Cli\Helpers;

use Aws\CloudFormation\CloudFormationClient;
use Aws\CloudFormation\Exception\CloudFormationException;
use Bref\Cli\Cli\IO;
use Exception;

/**
 * @phpstan-type ResourceStatus "CREATE_COMPLETE"|"CREATE_IN_PROGRESS"|"CREATE_FAILED"|"DELETE_COMPLETE"|"DELETE_FAILED"|"DELETE_IN_PROGRESS"|"ROLLBACK_COMPLETE"|"ROLLBACK_FAILED"|"ROLLBACK_IN_PROGRESS"|"UPDATE_COMPLETE"|"UPDATE_COMPLETE_CLEANUP_IN_PROGRESS"|"UPDATE_IN_PROGRESS"|"UPDATE_ROLLBACK_COMPLETE"|"UPDATE_ROLLBACK_COMPLETE_CLEANUP_IN_PROGRESS"|"UPDATE_ROLLBACK_FAILED"|"UPDATE_ROLLBACK_IN_PROGRESS"
 * @phpstan-type StackEvent array{LogicalResourceId?: string, ResourceStatus?: ResourceStatus, ResourceStatusReason?: string}
 */
class CloudFormation
{
    public function __construct(
        private readonly CloudFormationClient $cloudFormation,
    )
    {
    }

    /**
     * @param array<string, string> $parameters
     * @return bool Has deployed changes.
     * @throws Exception
     */
    public function deploy(string $stackName, string $templateUrl, array $parameters = []): bool
    {
        // If the CloudFormation stack already exists, update it, else create it
        $operation = fn(...$params) => $this->cloudFormation->updateStack(...$params);
        $waiter = 'StackUpdateComplete';
        try {
            $this->cloudFormation->describeStacks([
                'StackName' => $stackName,
            ]);
            IO::verbose("Updating CloudFormation stack $stackName");
        } catch (CloudFormationException $e) {
            if ($e->getAwsErrorCode() === 'ValidationError' && str_contains(($e->getAwsErrorMessage() ?: $e->getMessage()), 'does not exist')) {
                // This is not an error, it just means that the stack does not exist yet
                $operation = fn(...$params) => $this->cloudFormation->createStack(...$params);
                $waiter = 'StackCreateComplete';
                IO::verbose("Creating CloudFormation stack $stackName");
            } else {
                throw $e;
            }
        }

        try {
            $operation([
                'StackName' => $stackName,
                'TemplateURL' => $templateUrl,
                'Capabilities' => ['CAPABILITY_NAMED_IAM'],
                'Parameters' => array_map(function ($key, $value) {
                    return [
                        'ParameterKey' => $key,
                        'ParameterValue' => $value,
                    ];
                }, array_keys($parameters), $parameters),
            ]);
        } catch (CloudFormationException $e) {
            if ($e->getAwsErrorCode() === 'ValidationError' && str_contains($e->getMessage(), 'No updates are to be performed')) {
                // This is not an error, it just means that the stack is already up-to-date
                return false;
            }
            // In case of "is in ROLLBACK_COMPLETE state and can not be updated" we delete the stack
            if ($e->getAwsErrorCode() === 'ValidationError' && str_contains($e->getMessage(), 'ROLLBACK_COMPLETE')) {
                IO::verbose("The CloudFormation stack $stackName is in ROLLBACK_COMPLETE state and cannot be updated. Deleting the stack.");
                $this->delete($stackName);
                IO::verbose('The stack has been deleted. Retrying the deployment.');
                return $this->deploy($stackName, $templateUrl, $parameters);
            }
            throw $e;
        }

        IO::verbose("Waiting for CloudFormation stack $stackName to be deployed");

        try {
            $this->cloudFormation->waitUntil($waiter, [
                'StackName' => $stackName,
                '@waiter' => [
                    // Check every 1 second
                    'delay' => 1,
                    // Wait up to 10 minutes
                    'maxAttempts' => 600,
                ],
            ]);
        } catch (Exception $e) {
            $detailedError = null;
            try {
                $detailedError = $this->retrieveDetailedDeployError($stackName);
            } catch (Exception $subError) {
                // Ignore
                IO::verbose('Failed to retrieve details about the deployment error' . $subError->getMessage());
            }
            throw $detailedError ? new Exception($detailedError) : $e;
        }

        return true;
    }

    public function delete(string $stackName): void
    {
        $this->cloudFormation->deleteStack([
            'StackName' => $stackName,
        ]);
        $this->cloudFormation->waitUntil('StackDeleteComplete', [
            'StackName' => $stackName,
        ]);
    }

    private function retrieveDetailedDeployError(string $stackName): ?string
    {
        // In case of an error we retrieve the status of the stack to provide more context
        $stack = $this->cloudFormation->describeStacks([
            'StackName' => $stackName,
        ])->toArray();
        if (! empty($stack['Stacks'][0]['StackStatusReason'])) {
            throw $stack['Stacks'][0]['StackStatusReason'];
        }

        // Else try to retrieve the events
        $events = $this->getStackEventsForLastDeployment($stackName);
        $errors = array_filter(array_map(function ($event) {
            return str_contains($event['ResourceStatus'] ?? '', 'FAILED') ? ($event['ResourceStatusReason'] ?? '') : '';
        }, $events));

        if (! empty($errors)) {
            return "Deploying the stack $stackName failed with the following errors:\n" . implode("\n", $errors);
        }

        return null;
    }

    /**
     * Retrieve the stack events until the beginning of the stack deployment.
     *
     * @return array{LogicalResourceId?: string, ResourceStatus?: ResourceStatus, ResourceStatusReason?: string}[]
     */
    private function getStackEventsForLastDeployment(string $stackName): array
    {
        /** @var StackEvent[] $stackEvents */
        $stackEvents = [];
        $nextToken = null;
        do {
            /** @var array{StackEvents?: StackEvent[], NextToken?: string} $result */
            $result = $this->cloudFormation->describeStackEvents([
                'StackName' => $stackName,
                'NextToken' => $nextToken,
            ])->toArray();

            if (! empty($result['StackEvents'])) {
                $stackEvents = array_merge($stackEvents, $result['StackEvents']);
                foreach ($result['StackEvents'] as $event) {
                    // If we have reached the start of the deployment, we can stop
                    if ($this->isBeginningOfStackDeploy($stackName, $event)) {
                        break 2; // Break out of both foreach and do-while loop
                    }
                }
            }

            $nextToken = $result['NextToken'] ?? null;
        } while ($nextToken);

        // Truncate the events to the beginning of the last deployment
        $index = array_search(true, array_map(function ($event) use ($stackName) {
            return $this->isBeginningOfStackDeploy($stackName, $event);
        }, $stackEvents), true);
        if ($index === false) {
            return [];
        }

        return array_slice($stackEvents, 0, ((int) $index) + 1);
    }

    /**
     * @param StackEvent $event
     */
    private function isBeginningOfStackDeploy(string $stackName, array $event): bool
    {
        $resourceId = $event['LogicalResourceId'] ?? null;
        $status = $event['ResourceStatus'] ?? null;
        return $resourceId === $stackName
            && ($status === 'CREATE_IN_PROGRESS' || $status === 'UPDATE_IN_PROGRESS' || $status === 'DELETE_IN_PROGRESS');
    }

    /**
     * @return array<string, string>
     */
    public function getStackOutputs(string $stack_name): array
    {
        $stack = $this->cloudFormation->describeStacks([
            'StackName' => $stack_name,
        ])->toArray();
        $outputs = $stack['Stacks'][0]['Outputs'] ?? [];
        $result = [];
        foreach ($outputs as $output) {
            $result[$output['OutputKey']] = $output['OutputValue'];
        }
        return $result;
    }
}