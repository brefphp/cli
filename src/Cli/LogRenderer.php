<?php declare(strict_types=1);

namespace Bref\Cli\Cli;

/**
 * Renders the log records returned by Bref Cloud, which already parsed and truncated them.
 *
 * One line per record: `2026-09-23 10:12:51.863 web 45f01a ERROR message {"context"}`,
 * then the exception and its causes on indented lines, if any.
 *
 * @phpstan-type LogException array{class: string, message: string, file: string, frames: int, trace?: list<string>, previous?: array<string, mixed>}
 * @phpstan-type LogRecord array{timestamp: string, function: string, instance: string, level: string|null, message: string, context?: array<mixed>, extra?: array<mixed>, exception?: LogException}
 */
class LogRenderer
{
    private const MAX_CONTEXT_LENGTH = 500;
    private const INDENT = '    ';

    public function __construct(
        private readonly bool $colors,
        private readonly bool $full,
    ) {}

    /**
     * @param list<LogRecord> $records
     * @return list<string>
     */
    public function render(array $records): array
    {
        $functionWidth = max([0, ...array_map(fn(array $record) => strlen($record['function']), $records)]);
        $levelWidth = max([0, ...array_map(fn(array $record) => strlen($record['level'] ?? ''), $records)]);

        return array_map(fn(array $record) => $this->renderRecord($record, $functionWidth, $levelWidth), $records);
    }

    /**
     * @param LogRecord $record
     */
    private function renderRecord(array $record, int $functionWidth, int $levelWidth): string
    {
        $columns = [
            $this->gray(str_replace('T', ' ', rtrim($record['timestamp'], 'Z'))),
            str_pad($record['function'], $functionWidth),
            $this->gray($record['instance']),
        ];
        // Only logs written by Bref's Monolog formatter have a level: there is no column for apps that don't use it
        if ($levelWidth > 0) {
            $columns[] = $this->level(str_pad($record['level'] ?? '', $levelWidth));
        }
        $line = implode(' ', $columns) . ' ' . $this->indent($record['message']);

        foreach (['context', 'extra'] as $key) {
            if (! empty($record[$key])) {
                $line .= ' ' . $this->gray($this->truncate((string) json_encode($record[$key], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)));
            }
        }

        if (isset($record['exception'])) {
            $line .= "\n" . $this->renderException($record['exception']);
        }

        return $line;
    }

    /**
     * @param LogException $exception
     */
    private function renderException(array $exception, bool $isPrevious = false): string
    {
        $prefix = self::INDENT . '↳ ' . ($isPrevious ? 'Caused by ' : '');
        // Bref's runtime errors have no file
        $location = $exception['file'] !== '' ? " at {$exception['file']}" : '';

        if ($this->full) {
            $lines = [$prefix . $this->red($exception['class']) . ': ' . $this->indent($exception['message'], 2)];
            if ($location !== '') {
                $lines[] = self::INDENT . '  ' . $this->gray(ltrim($location));
            }
            foreach ($exception['trace'] ?? [] as $i => $frame) {
                $lines[] = self::INDENT . '  ' . $this->gray("#$i $frame");
            }
        } elseif ($isPrevious) {
            // Its message is usually the actual cause, e.g. the HTTP error behind a RuntimeException
            $lines = [$prefix . $this->red($exception['class']) . ': ' . $this->indent($exception['message'], 2)];
        } else {
            // Its message is usually the log message already
            $frames = $exception['frames'] > 0 ? " ({$exception['frames']} frames)" : '';
            $lines = [$prefix . $this->red($exception['class']) . $this->gray($location . $frames)];
        }

        if (isset($exception['previous'])) {
            /** @var LogException $previous */
            $previous = $exception['previous'];
            $lines[] = $this->renderException($previous, true);
        }

        return implode("\n", $lines);
    }

    /**
     * Indent the continuation lines of a multi-line message, to keep them apart from the next record.
     */
    private function indent(string $message, int $extra = 0): string
    {
        return str_replace("\n", "\n" . self::INDENT . str_repeat(' ', $extra), rtrim($message, "\n"));
    }

    private function truncate(string $text): string
    {
        $length = mb_strlen($text);
        if ($this->full || $length <= self::MAX_CONTEXT_LENGTH) {
            return $text;
        }

        return mb_substr($text, 0, self::MAX_CONTEXT_LENGTH) . '…(+' . ($length - self::MAX_CONTEXT_LENGTH) . ' chars)';
    }

    private function level(string $level): string
    {
        return match (trim($level)) {
            'ERROR', 'CRITICAL', 'ALERT', 'EMERGENCY' => $this->red($level),
            'WARNING' => $this->colors ? Styles::yellow($level) : $level,
            'DEBUG' => $this->gray($level),
            default => $level,
        };
    }

    private function gray(string $text): string
    {
        return $this->colors ? Styles::gray($text) : $text;
    }

    private function red(string $text): string
    {
        return $this->colors ? Styles::red($text) : $text;
    }
}
