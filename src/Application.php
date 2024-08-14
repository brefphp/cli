<?php declare(strict_types=1);

namespace Bref\Cli;

use Bref\Cli\Cli\IO;
use Exception;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\ConsoleOutput;
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

    public function run(?InputInterface $input = null, ?OutputInterface $output = null): int
    {
        $input ??= new ArgvInput();
        $output ??= new ConsoleOutput();
        IO::init($input, $output);

        return parent::run($input, $output);
    }

    public function renderThrowable(Throwable $e, OutputInterface $output): void
    {
        // Prettify Bref Cloud errors
        if ($e instanceof ClientException) {
            try {
                $body = $e->getResponse()->toArray(false);
                $message = $body['message'] ?? 'Unknown Bref Cloud error';
                $statusCode = $e->getResponse()->getStatusCode();
                $e = new Exception("[$statusCode] $message", $statusCode);
            } catch (Throwable) {
            }
        }

        parent::renderThrowable($e, $output);
    }
}