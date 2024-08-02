<?php declare(strict_types=1);

use Bref\Cli\Commands\Deploy;
use Bref\Cli\Commands\Login;
use Bref\Cli\Helpers\BrefSpinnerRenderer;
use Laravel\Prompts\Prompt;
use Laravel\Prompts\Spinner;
use Symfony\Component\Console\Application;

require __DIR__ . '/vendor/autoload.php';

Prompt::addTheme('bref', [
    Spinner::class => BrefSpinnerRenderer::class,
]);
Prompt::theme('bref');

$application = new Application('bref');
$application->add(new Login);
$application->add(new Deploy);
$application->run();
