<?php declare(strict_types=1);

namespace Bref\Cli\Commands;

use Bref\Cli\BrefCloudClient;
use Bref\Cli\Config;
use Bref\Cli\Helpers\Styles;
use Exception;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;

class Login extends Command
{
    protected function configure(): void
    {
        $this
            ->setName('login')
            ->setDescription('Connect the CLI to your Bref Cloud account');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln([Styles::brefHeader(), '']);

        $url = BrefCloudClient::getUrl() . '/cli/connect';
        $output->writeln([
            'Please open the following URL and click the "Connect CLI" menu.',
            Styles::gray(Styles::underline($url)),
            '',
            'Once that is done, please paste your Bref Cloud token below.',
            '',
        ]);
        $this->open($url);

        $helper = $this->getHelper('question');
        $question = new Question('Bref Cloud token:');
        $question->setHidden(true)
            ->setHiddenFallback(false)
            ->setTrimmable(true);
        $token = $helper->ask($input, $output, $question);
        if (! $token) {
            $output->writeln('No token provided, aborting.');
            return 1;
        }

        // Test the API token by getting the user data
        $brefCloud = new BrefCloudClient($token);
        try {
            $user = $brefCloud->getUserInfo();
        } catch (HttpExceptionInterface $e) {
            $output->writeln(Styles::red("There was an error while connecting to Bref Cloud using the token, is the token valid?\nError: " . $e->getMessage()));
            return 1;
        }

        Config::storeToken($brefCloud->url, $token);

        $output->writeln([
            '',
            "Welcome to Bref Cloud {$user['name']}, you are now logged in 🎉",
        ]);

        return 0;
    }

    private function open(string $url): void
    {
        switch (php_uname('s')) {
            case 'Darwin':
                exec('open ' . escapeshellarg($url));
                break;
            case 'Windows':
                exec('start ' . escapeshellarg($url));
                break;
            default:
                if (exec('which xdg-open')) {
                    exec('xdg-open ' . escapeshellarg($url));
                }
                break;
        }
    }
}