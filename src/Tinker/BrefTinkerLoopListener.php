<?php

namespace Bref\Cli\Tinker;

use Bref\Cli\Commands\Command;
use GuzzleHttp\Exception\ClientException;
use Psy\Exception\BreakException;
use Psy\Exception\ThrowUpException;
use Psy\ExecutionClosure;
use Psy\ExecutionLoop\AbstractListener;
use Psy\Shell;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\StringInput;
use Symfony\Component\Console\Output\BufferedOutput;

class BrefTinkerLoopListener extends AbstractListener
{
    protected string $commandInput;

    public function __construct(string $commandInput)
    {
        $this->commandInput = $commandInput;
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
        if ($code == '\Psy\Exception\BreakException::exitShell();') {
            return $code;
        }

        $vars = $shell->getScopeVariables(false);
        $context = $vars['_context'] ?? base64_encode(serialize(["_" => null]));
        // Evaluate the current code buffer
        try {
            $command = new Command();
            $args = implode(" ", [
                'bref:tinker',
                '--execute=\"'.base64_encode($code).'\"',
                '--context=\"'.$context.'\"',
            ]);
            $output = new BufferedOutput();
            $input = new ArgvInput(array_merge((new StringInput($this->commandInput))->getRawTokens(), [$args]));
            
            $resultCode = $command->run($input, $output);
            $resultOutput = $output->fetch();
            if ($resultCode !== 0) {
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
                    return "extract(['_context' => '{$context}']); return new \Psy\CodeCleaner\NoReturnValue();";
                } else {
                    // Return NoValue as output and return value were printed out
                    return "return new \Psy\CodeCleaner\NoReturnValue();";
                }
            }

            return ExecutionClosure::NOOP_INPUT;
        } catch (ClientException $_e) {
            throw new BreakException($_e->getMessage());
        } catch (BreakException $breakException) {
            throw $breakException;
        } catch (\Throwable $throwable) {
            throw new ThrowUpException($throwable);
        }
    }
}
