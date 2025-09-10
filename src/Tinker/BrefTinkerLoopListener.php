<?php declare(strict_types=1);

namespace Bref\Cli\Tinker;

use Bref\Cli\BrefCloudClient;
use Bref\Cli\Cli\IO;
use Bref\Cli\Cli\Styles;
use Psy\Exception\BreakException;
use Psy\Exception\ThrowUpException;
use Psy\ExecutionClosure;
use Psy\ExecutionLoop\AbstractListener;
use Psy\Shell;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;
use Throwable;
use function Amp\delay;

class BrefTinkerLoopListener extends AbstractListener
{
    public function __construct(
        private readonly int $environmentId,
        private readonly BrefCloudClient $brefCloudClient,
    )
    {
    }

    public static function isSupported(): bool
    {
        return true;
    }

    /**
     * @param BrefTinkerShell $shell
     * @throws BreakException
     * @throws ThrowUpException
     */
    public function onExecute(Shell $shell, string $code)
    {
        if ($code === '\Psy\Exception\BreakException::exitShell();') {
            return $code;
        }

        $vars = $shell->getScopeVariables(false);
        $context = $vars['_context'] ?? base64_encode(serialize(["_" => null]));
        // Evaluate the current code buffer
        try {
            [$resultCode, $resultOutput] = $this->evaluateCode($code, $context);
            if ($resultCode !== 0) {
                $shell->rawOutput->writeln($resultOutput);
                throw new BreakException("The remote tinker shell returned an error (code $resultCode).");
            }

            $extractedOutput = $shell->extractContextData($resultOutput);
            if (is_null($extractedOutput)) {
                $shell->rawOutput->writeln('  <info> INFO </info> Please upgrade <string>laravel-bridge</string> package to latest version.');
                throw new BreakException("The remote tinker shell returned an invalid payload");
            }

            if ([$output, $context, $return] = $extractedOutput) {
                if (!empty($output)) {
                    $shell->rawOutput->writeln($output);
                }
                if (!empty($return)) {
                    $shell->rawOutput->writeln($return);
                }
                if (!empty($context)) {
                    // Extract _context into shell's scope variables for next code execution
                    // Return NoValue as output and return value were printed out
                    return "extract(['_context' => '$context']); return new \Psy\CodeCleaner\NoReturnValue();";
                }
                // Return NoValue as output and return value were printed out
                return "return new \Psy\CodeCleaner\NoReturnValue();";
            }

            return ExecutionClosure::NOOP_INPUT;
        } catch (Throwable $e) {
            throw new BreakException($e->getMessage());
        }
    }

    /**
     * @return array{0: int, 1: string} [exitCode, output]
     * @throws ExceptionInterface
     * @throws HttpExceptionInterface
     */
    protected function evaluateCode(string $code, string $context): array
    {
        $command = implode(" ", [
            'bref:tinker',
            '--execute=\"'.base64_encode($code).'\"',
            '--context=\"'.$context.'\"',
        ]);
        $id = $this->brefCloudClient->startCommand($this->environmentId, $command);

        // Timeout after 2 minutes and 10 seconds
        $timeout = 130;
        $startTime = time();

        while (true) {
            $invocation = $this->brefCloudClient->getCommand($id);

            if ($invocation['status'] === 'success') {
                return [0, $invocation['output']];
            }

            if ($invocation['status'] === 'failed') {
                return [1, $invocation['output']];
            }

            if ((time() - $startTime) > $timeout) {
                IO::writeln(Styles::red('Timed out'));
                IO::writeln(Styles::gray('The execution timed out after 2 minutes, the command might still be running'));
                return [1, 'Timed out'];
            }

            delay(0.5);
        }
    }
}
