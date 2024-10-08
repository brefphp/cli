<?php declare(strict_types=1);

namespace Bref\Cli;

use Aws\Exception\CredentialsException;
use Bref\Cli\Cli\IO;
use Exception;
use Revolt\EventLoop;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\HttpClient\Exception\ClientException;
use Throwable;

class Application extends \Symfony\Component\Console\Application
{
    public function __construct()
    {
        parent::__construct('bref');

        $this->add(new Commands\Login);
        $this->add(new Commands\Deploy);
        $this->add(new Commands\Command);
        $this->add(new Commands\Connect);
    }

    public function doRun(InputInterface $input, OutputInterface $output): int
    {
        IO::init($input, $output);

        $result = parent::doRun($input, $output);

        IO::stop();

        // Run the event loop until all tasks are done
        EventLoop::run();

        return $result;
    }

    public function renderThrowable(Throwable $e, OutputInterface $output): void
    {
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

                $e = new Exception("[$statusCode] $message", $statusCode);
            } catch (Throwable) {
            }
        }

        // Prettify AWS credentials errors
        if ($e instanceof CredentialsException && str_contains($e->getMessage(), 'not found in credentials file')) {
            $this->renderUserError('AWS profile not found: ' . $e->getMessage(), $output);
            return;
        }

        parent::renderThrowable($e, $output);
    }

    private function renderUserError(string $message, OutputInterface $output): void
    {
        $output->writeln([
            '',
            '<error>  ' . str_repeat(' ', strlen($message)) . '  </error>',
            '<error>  ' . $message . '  </error>',
            '<error>  ' . str_repeat(' ', strlen($message)) . '  </error>',
        ]);
    }
}