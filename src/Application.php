<?php declare(strict_types=1);

namespace Bref\Cli;

use Aws\Exception\CredentialsException;
use Bref\Cli\Cli\IO;
use Bref\Cli\Cli\Styles;
use ErrorException;
use Exception;
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

        $this->add(new Commands\Login);
        $this->add(new Commands\Deploy);
        $this->add(new Commands\Info);
        $this->add(new Commands\Remove);
        $this->add(new Commands\Command);
        $this->add(new Commands\Connect);
        $this->add(new Commands\PreviousLogs);
        $this->add(new Commands\Cloud);
        $this->add(new Commands\Tinker);
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

        // Prettify Bref Cloud errors
        if ($e instanceof ClientException) {
            try {
                $body = $e->getResponse()->toArray(false);
                $message = $body['message'] ?? 'Unknown Bref Cloud error';
                $statusCode = $e->getResponse()->getStatusCode();

                $message = match ($statusCode) {
                    401 => 'Unauthenticated. Please log in with `bref login`.',
                    403 => 'Forbidden. You do not have the required permissions. Do you need to login to a different team?',
                    default => $message,
                };

                $e = new Exception("Bref Cloud API error: [$statusCode] $message", $statusCode);
            } catch (Throwable) {
            }
        }

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

    private function turnWarningsIntoExceptions(): void
    {
        set_error_handler(function (int $errno, string $errstr, string $errfile, int $errline) {
            throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
        });
    }
}
