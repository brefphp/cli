<?php declare(strict_types=1);

namespace Bref\Cli;

use Aws\Exception\CredentialsException;
use Bref\Cli\Cli\IO;
use Bref\Cli\Cli\Styles;
use ErrorException;
use Exception;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\HttpClient\Exception\ClientException;
use Throwable;

class Application extends \Symfony\Component\Console\Application
{
    public function __construct()
    {
        parent::__construct('bref');

        $this->turnWarningsIntoExceptions();

        $this->safeAddCommand(new Commands\Login);
        $this->safeAddCommand(new Commands\Whoami);
        $this->safeAddCommand(new Commands\Teams);
        $this->safeAddCommand(new Commands\Deploy);
        $this->safeAddCommand(new Commands\Info);
        $this->safeAddCommand(new Commands\Remove);
        $this->safeAddCommand(new Commands\Command);
        $this->safeAddCommand(new Commands\Connect);
        $this->safeAddCommand(new Commands\PreviousLogs);
        $this->safeAddCommand(new Commands\Cloud);
        $this->safeAddCommand(new Commands\Tinker);
        $this->safeAddCommand(new Commands\SecretCreate);
        $this->safeAddCommand(new Commands\Logs);
        $this->safeAddCommand(new Commands\Deployments);
        $this->safeAddCommand(new Commands\DeploymentsShow);
        $this->safeAddCommand(new Commands\DeploymentsLogs);
    }

    public function safeAddCommand(Command $command): ?Command
    {
        // addCommand() exists since Symfony Console 7.4; add() was removed in Symfony 8.
        $method = method_exists(parent::class, 'addCommand') ? 'addCommand' : 'add';

        /** @var callable(Command): ?Command $register */
        $register = [$this, $method];

        return $register($command);
    }

    public function doRun(InputInterface $input, OutputInterface $output): int
    {
        IO::init($input, $output);

        if ($input->hasParameterOption(['--stage'], true)) {
            throw new Exception('The "--stage" option does not exist in the "bref" CLI. Use the "--env" option instead.');
        }

        $result = parent::doRun($input, $output);

        IO::stop();

        return $result;
    }

    public function renderThrowable(Throwable $e, OutputInterface $output): void
    {
        IO::spinClear();

        $e = self::prettifyException($e);

        // Prettify AWS credentials errors
        if ($e instanceof CredentialsException && str_contains($e->getMessage(), 'not found in credentials file')) {
            IO::error(new Exception('AWS profile not found: ' . $e->getMessage()), false);
            return;
        }

        if (! IO::isVerbose()) {
            IO::writeln(Styles::gray('verbose logs are available by running `bref previous-logs`'));
        }
        IO::error($e);
    }

    /**
     * Turn Bref Cloud API errors into their message.
     */
    public static function prettifyException(Throwable $e): Throwable
    {
        if (! $e instanceof ClientException) {
            return $e;
        }
        try {
            $body = $e->getResponse()->toArray(false);
            $message = $body['message'] ?? 'Unknown Bref Cloud error';
            $statusCode = $e->getResponse()->getStatusCode();

            // Laravel's messages for a failed authorization, which say nothing about the cause
            $isGenericForbidden = in_array($body['message'] ?? '', ['', 'This action is unauthorized.'], true);

            $message = match (true) {
                $statusCode === 401 => 'Unauthenticated. Please log in with `bref login`.',
                // Other 403 responses explain their cause (e.g. Bref Cloud cannot access the AWS account)
                $statusCode === 403 && $isGenericForbidden => 'Forbidden. You do not have the required permissions. Do you need to login to a different team?',
                $statusCode === 429 => 'Too many requests, try again in a minute.',
                default => $message,
            };

            return new Exception("Bref Cloud API error: [$statusCode] $message", $statusCode);
        } catch (Throwable) {
            return $e;
        }
    }

    private function turnWarningsIntoExceptions(): void
    {
        set_error_handler(function (int $errno, string $errstr, string $errfile, int $errline) {
            throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
        });
    }
}
