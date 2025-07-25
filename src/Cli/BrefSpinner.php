<?php declare(strict_types=1);

namespace Bref\Cli\Cli;

use Revolt\EventLoop;
use Symfony\Component\Console\Cursor;
use Symfony\Component\Console\Exception\LogicException;
use Symfony\Component\Console\Output\OutputInterface;

class BrefSpinner
{
    private const FRAMES = [
        ' ',
        '⠁',
        '⠃',
        '⠇',
        '⠧',
        '⠷',
        '⠷',
        '⠷',
        '⠶',
        '⠴',
        '⠰',
        '⠐',
        ' ',
        ' ',
        '⠐',
        '⠰',
        '⠴',
        '⠶',
        '⠷',
        '⠷',
        '⠷',
        '⠧',
        '⠇',
        '⠃',
        '⠁',
        ' ',
    ];
    private int $startTime;
    private string $message;
    private ?string $extraMessage = null;
    private int $currentFrame = 7;
    private bool $started = true;
    private string $timerId;
    private ?Cursor $cursor;
    private int $interactiveLines = 0;
    private string $previousMessage = '';

    public function __construct(private readonly OutputInterface $output, string $message)
    {
        $this->startTime = time();
        $this->message = $message;
        $this->cursor = IO::isInteractive() ? new Cursor($output) : null;

        VerboseModeEnabler::start();

        $this->render();

        $this->timerId = EventLoop::repeat(0.1, function () {
            $this->advance();
        });
    }

    public function setMessage(string $message): void
    {
        $this->message = $message;

        $this->render();
    }

    public function advance(): void
    {
        if (! $this->started) return;
        if (! IO::isInteractive()) return;

        ++$this->currentFrame;

        $this->render();
    }

    /**
     * Finish the indicator with message.
     */
    public function finish(string $message, ?string $extraMessage = null): void
    {
        if (! $this->started) throw new LogicException('Progress indicator has not yet been started.');

        VerboseModeEnabler::stop();

        EventLoop::cancel($this->timerId);

        $this->currentFrame = 5;
        $this->message = $message;
        $this->extraMessage = $extraMessage;
        $this->render();
        $this->output->writeln('');
        $this->started = false;
    }

    public function render(): void
    {
        if (OutputInterface::VERBOSITY_QUIET === $this->output->getVerbosity()) return;
        if (! $this->started) return;

        // In non-interactive mode, only print the message if it changed
        if ($this->previousMessage === $this->message && ! IO::isInteractive()) {
            return;
        }

        if (IO::isInteractive()) {
            $frames = array_map(fn (string $frame) => Styles::blue($frame), self::FRAMES);
            $frame = $frames[$this->currentFrame % count($frames)];
        } else {
            // Non-interactive mode
            $frame = Styles::blue('⠷');
        }

        $timeFormatted = $this->formatTime(time() - $this->startTime);
        if ($this->extraMessage) {
            // Finished with success
            $line = Styles::gray($timeFormatted . ' › ') . $this->message . Styles::gray(' › ') . $this->extraMessage;
        } else {
            $line = ' ' . $frame . ' ' . $this->message . Styles::gray(' › ' . $timeFormatted);
        }
        $this->overwrite($line);

        $this->previousMessage = $this->message;
    }

    public function stopAndClear(): void
    {
        VerboseModeEnabler::stop();
        EventLoop::cancel($this->timerId);
        $this->clear();
    }

    public function clear(): void
    {
        $this->cursor?->moveToColumn(0);
        for ($i = 0; $i < $this->interactiveLines; $i++) {
            $this->cursor?->clearLine();
            $this->cursor?->moveUp();
        }
        $this->cursor?->clearLine();
        $this->interactiveLines = 0;
    }

    /**
     * Overwrites a previous message to the output.
     */
    private function overwrite(string $message): void
    {
        if (IO::isInteractive()) {
            $this->clear();

            // Add an empty line of separation
            $this->output->writeln('');
            $this->interactiveLines++;
            $this->output->writeln($message);
            $this->interactiveLines++;
            if (VerboseModeEnabler::isRunning()) {
                $this->output->writeln('');
                $this->interactiveLines++;
                // Do not finish on a new line
                $this->output->write(Styles::gray('press [?] for verbose logs'));
            }
        } else {
            $this->output->writeln($message);
        }
    }

    private function formatTime(int|float $secs): string
    {
        // If < 100s, show seconds
        if ($secs < 100) {
            return ((int) floor($secs)) . 's';
        }
        // else show minutes
        $mins = (int) floor($secs / 60);
        $secs = (int) floor($secs % 60);
        return $mins . 'm' . $secs . 's';
    }
}