<?php declare(strict_types=1);

namespace Bref\Cli\Cli;

use Amp\ByteStream\ReadableResourceStream;
use Amp\DeferredCancellation;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\StreamableInputInterface;
use function Amp\async;

/**
 * This class enables verbose mode when the user types '?' in the console.
 */
class VerboseModeEnabler
{
    private static InputInterface $input;
    private static ?DeferredCancellation $verboseWatchCancellation = null;

    public static function init(InputInterface $input): void
    {
        self::$input = $input;
    }

    public static function start(): void
    {
        self::$verboseWatchCancellation = null;

        if (IO::isVerbose()) return;

        // Set this before starting the async function, else the cancellation might have
        // been requested before the async function starts
        self::$verboseWatchCancellation = new DeferredCancellation;

        async(function () {
            if (! (self::$input instanceof StreamableInputInterface) || ! IO::isInteractive()) return;
            $resourceStream = self::$input->getStream() ?: STDIN;
            if (! is_resource($resourceStream)) return;

            // Safety in case things were stopped before the async function started
            if (! self::$verboseWatchCancellation) return;
            $cancellation = self::$verboseWatchCancellation->getCancellation();
            $cancellation->throwIfRequested();

            // Some of the techniques used here are inspired from the Symfony Console component
            // See \Symfony\Component\Console\Helper\QuestionHelper::autocomplete()

            // Disable icanon (so we can fread each keypress)
            $previousSttyMode = self::getSttyMode();
            if ($previousSttyMode === false) return;
            self::disableSttyIcanonMode();
            $cancellation->subscribe(function () use ($previousSttyMode) {
                // Reset stty so it behaves normally again
                self::setSttyMode($previousSttyMode);
            });

            $stream = new ReadableResourceStream($resourceStream, 1);
            while (($chunk = $stream->read($cancellation)) !== null) {
                if (str_contains($chunk, '?')) {
                    IO::enableVerbose();
                    self::$verboseWatchCancellation = null;
                    break;
                }
                if ($cancellation->isRequested()) {
                    break;
                }
            }

            // Reset stty so it behaves normally again
            self::setSttyMode($previousSttyMode);
        });
    }

    public static function isRunning(): bool
    {
        return self::$verboseWatchCancellation !== null;
    }

    public static function stop(): void
    {
        self::$verboseWatchCancellation?->cancel();
        self::$verboseWatchCancellation = null;
    }

    private static function getSttyMode(): string|null|false
    {
        return shell_exec('stty -g');
    }

    private static function setSttyMode(string|null $sttyMode): void
    {
        if ($sttyMode !== null) {
            shell_exec('stty ' . $sttyMode);
        }
    }

    private static function disableSttyIcanonMode(): void
    {
        shell_exec('stty -icanon');
    }
}
