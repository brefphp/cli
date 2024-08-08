<?php declare(strict_types=1);

namespace Bref\Cli;

use Bref\Cli\Commands\Command;
use Bref\Cli\Commands\Deploy;
use Bref\Cli\Commands\Login;
use Bref\Cli\Helpers\BrefSpinnerRenderer;
use Exception;
use Laravel\Prompts\Prompt;
use Laravel\Prompts\Spinner;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\HttpClient\Exception\ClientException;
use Throwable;

class Application extends \Symfony\Component\Console\Application
{
    public function __construct()
    {
        parent::__construct('bref');

        Prompt::addTheme('bref', [
            Spinner::class => BrefSpinnerRenderer::class,
        ]);
        Prompt::theme('bref');

        $this->add(new Login);
        $this->add(new Deploy);
        $this->add(new Command);
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