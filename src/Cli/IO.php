<?php declare(strict_types=1);

namespace Bref\Cli\Cli;

use Symfony\Component\Console\Exception\RuntimeException;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Throwable;

class IO
{
    private static InputInterface $input;
    private static OutputInterface $output;
    private static BrefSpinner|null $spinner = null;
    private static bool $verboseMode = false;
    /** @var string[] */
    private static array $verboseLogs = [];
    /** @var resource|false */
    private static $logsFileResource;

    public static function init(InputInterface $input, OutputInterface $output): void
    {
        self::$input = $input;
        self::$output = $output;

        self::$verboseMode = $output->isVerbose();
        // We also want to enable verbose by default for non-interactive environments like CI
        if (! self::isInteractive()) {
            self::$verboseMode = true;
        }

        // Store verbose logs in the temp directory
        $logsFilePath = sys_get_temp_dir() . '/bref.log';
        $previousLogsFilePath = sys_get_temp_dir() . '/bref-previous.log';
        if (file_exists($previousLogsFilePath)) {
            unlink($previousLogsFilePath);
        }
        if (file_exists($logsFilePath)) {
            rename($logsFilePath, $previousLogsFilePath);
        }
        self::$logsFileResource = fopen($logsFilePath, 'wb');

        VerboseModeEnabler::init($input);
    }

    public static function stop(): void
    {
        if (self::$logsFileResource) {
            fclose(self::$logsFileResource);
        }
    }

    /**
     * Write on stdout.
     * @param string|string[] $messages
     */
    public static function writeln(string|array $messages): void
    {
        self::safeWrite($messages);
        self::writeToLogsFile($messages);
    }

    /**
     * Log at the verbose level.
     * @param string|string[] $messages
     */
    public static function verbose(string|array $messages): void
    {
        if (self::$verboseMode) {
            self::doLogVerbose($messages);
        } else {
            $messages = is_array($messages) ? $messages : [$messages];
            self::$verboseLogs = array_merge(self::$verboseLogs, $messages);
        }
        self::writeToLogsFile($messages);
    }

    public static function error(Throwable $e, bool $logStackTrace = true): void
    {
        $exceptionClass = get_class($e);
        $parts = explode('\\', $exceptionClass);
        $shortClassName = end($parts);

        if ($logStackTrace) {
            self::verbose($exceptionClass . ': ' . $e->getMessage());
            $stackTrace = $e->getTraceAsString();
            // Simplify the stack trace by removing the path to the current project
            $cliPath = dirname(dirname(__DIR__)) . '/';
            $stackTrace = str_replace($cliPath, '', $stackTrace);
            self::verbose($stackTrace);
        }

        self::writeln([
            '',
            Styles::bold(Styles::red('× ' . $e->getMessage())) . Styles::gray(' ' . $shortClassName),
        ]);
    }

    public static function enableVerbose(): void
    {
        if (self::$verboseMode) return;

        self::$verboseMode = true;
        // Flush all previous verbose logs to the output
        self::doLogVerbose(self::$verboseLogs);
    }

    /**
     * Asks a question to the user.
     *
     * @return mixed The user answer
     * @throws RuntimeException If there is no data to read in the input stream
     */
    public static function ask(Question $question): mixed
    {
        return (new QuestionHelper)->ask(self::$input, self::$output, $question);
    }

    public static function spin(string $message): void
    {
        if (OutputInterface::VERBOSITY_QUIET === self::$output->getVerbosity()) return;

        if (! self::$spinner) {
            self::$spinner = new BrefSpinner(self::$output, $message);
        } else {
            self::$spinner->setMessage($message);
        }

        // Also log to verbose logs
        self::verbose(ucfirst($message));
    }

    public static function spinError(string $message = 'error'): void
    {
        self::$spinner?->finish($message);
        self::$spinner = null;
    }

    public static function spinSuccess(string $message): void
    {
        self::$spinner?->finish($message);
        self::$spinner = null;
    }

    public static function spinClear(): void
    {
        self::$spinner?->stopAndClear();
        self::$spinner = null;
    }

    public static function isVerbose(): bool
    {
        return self::$verboseMode;
    }

    public static function isInteractive(): bool
    {
        return self::$input->isInteractive() && self::$output->isDecorated();
    }

    public static function previousVerboseLogs(): string
    {
        return (string) file_get_contents(sys_get_temp_dir() . '/bref-previous.log');
    }

    /**
     * @param string|string[] $messages
     */
    private static function writeToLogsFile(string|array $messages): void
    {
        if (! self::$logsFileResource) return;

        $message = is_array($messages) ? implode(PHP_EOL, $messages) : $messages;
        // Strip ANSI
        $message = (string) preg_replace('/\x1b\[[0-9;]*m/', '', $message);
        foreach (explode(PHP_EOL, $message) as $line) {
            if (empty(trim($line))) continue;
            fwrite(self::$logsFileResource, $line . PHP_EOL);
        }
    }

    /**
     * @param string|string[] $messages
     */
    private static function doLogVerbose(string|array $messages): void
    {
        $message = is_array($messages) ? implode(PHP_EOL, $messages) : $messages;

        $messages = array_filter(array_map(function (string $line) {
            if (empty(trim($line))) return '';
            return Styles::gray('› ' . $line);
        }, explode(PHP_EOL, $message)));

        self::safeWrite($messages);
    }

    /**
     * @param string|string[] $messages
     */
    private static function safeWrite(string|array $messages): void
    {
        if (OutputInterface::VERBOSITY_QUIET === self::$output->getVerbosity()) return;

        self::$spinner?->clear();

        self::$output->writeln($messages);

        // Render the spinner again
        self::$spinner?->render();
    }
}
