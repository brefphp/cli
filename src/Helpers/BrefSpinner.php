<?php declare(strict_types=1);

namespace Bref\Cli\Helpers;

use Bref\Cli\Cli\VerboseModeEnabler;
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
    private int $currentFrame = 0;
    private bool $started = true;
    private string $timerId;
    private ?Cursor $cursor;
    private int $interactiveLines = 0;
    private string $previousMessage = '';

    public function __construct(private readonly OutputInterface $output, string $message)
    {
        $this->startTime = time();
        $this->message = $message;
        $this->cursor = $this->output->isDecorated() ? new Cursor($output) : null;

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

        VerboseModeEnabler::stop();

        EventLoop::cancel($this->timerId);

        $this->currentFrame = 5;
        $this->message = $message;
        $this->render();
        $this->output->writeln('');
        $this->started = false;
    }

    public function render(): void
    {
        if (OutputInterface::VERBOSITY_QUIET === $this->output->getVerbosity()) return;
        if (! $this->started) return;

        // In non-interactive mode, only print the message if it changed
        if (! $this->output->isDecorated() && $this->previousMessage === $this->message) {
            return;
        }

        if ($this->output->isDecorated()) {
            $frames = array_map(fn (string $frame) => Styles::blue($frame), self::FRAMES);
            $frame = $frames[$this->currentFrame % count($frames)];
        } else {
            // Non-interactive mode
            $frame = Styles::blue('⠷');
        }

        $line = ' ' . $frame . ' ' . $this->message . Styles::gray(' › ' . $this->formatTime(time() - $this->startTime));
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
        if ($this->output->isDecorated()) {
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
        return ((int) floor($secs)) . 's';
    }
}