<?php declare(strict_types=1);

namespace Bref\Cli\Helpers;

use Revolt\EventLoop;
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
    private int $currentFrame = 0;
    private bool $started = true;
    private string $timerId;

    public function __construct(private readonly OutputInterface $output, string $message)
    {
        $this->startTime = time();
        $this->message = $message;

        // First render should not clear previous lines
        $this->render(clear: false);

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
        if (! $this->output->isDecorated()) return;

        ++$this->currentFrame;

        $this->render();
    }

    /**
     * Finish the indicator with message.
     */
    public function finish(string $message): void
    {
        if (! $this->started) throw new LogicException('Progress indicator has not yet been started.');

        EventLoop::cancel($this->timerId);

        $this->currentFrame = 5;
        $this->message = $message;
        $this->render();
        $this->output->writeln('');
        $this->started = false;
    }

    public function render(bool $clear = true): void
    {
        if (OutputInterface::VERBOSITY_QUIET === $this->output->getVerbosity()) return;
        if (! $this->started) return;

        $frames = array_map(fn (string $frame) => Styles::blue($frame), self::FRAMES);

        $frame = $frames[$this->currentFrame % count($frames)];
        $line = ' ' . $frame . ' ' . $this->message . Styles::gray(' › ' . $this->formatTime(time() - $this->startTime));
        $this->overwrite($line, $clear);
    }

    public function stopAndClear(): void
    {
        EventLoop::cancel($this->timerId);
        $this->clear();
    }

    private function clear(): void
    {
        if ($this->output->isDecorated()) {
            // Clear the entire last line
            $this->output->write("\x1b[2K");
            // Move the cursor to the beginning of the line
            $this->output->write("\x0D");
            // Move up one line
            $this->output->write("\x1b[1A");
        }
    }

    /**
     * Overwrites a previous message to the output.
     */
    private function overwrite(string $message, bool $clear): void
    {
        if ($this->output->isDecorated()) {
            if ($clear) {
                $this->clear();
            }

            // Add an empty line of separation
            $this->output->writeln('');
            $this->output->write($message);
        } else {
            $this->output->writeln($message);
        }
    }

    private function formatTime(int|float $secs): string
    {
        return ((int) floor($secs)) . 's';
    }
}