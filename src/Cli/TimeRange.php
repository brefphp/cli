<?php declare(strict_types=1);

namespace Bref\Cli\Cli;

use DateTimeImmutable;
use DateTimeZone;
use Exception;

class TimeRange
{
    private const UNITS = ['s' => 1, 'm' => 60, 'h' => 3600, 'd' => 86400, 'w' => 604800];

    /**
     * @param string $value A duration before now (`30m`, `2h`, `7d`), or a date or a datetime (UTC unless it has an offset).
     * @return int Unix timestamp.
     * @throws Exception
     */
    public static function parse(string $value, int $now): int
    {
        $value = trim($value);

        if (preg_match('/^(\d+)\s*([smhdw])$/', $value, $matches) === 1) {
            return $now - (int) $matches[1] * self::UNITS[$matches[2]];
        }

        try {
            return (new DateTimeImmutable($value, new DateTimeZone('UTC')))->getTimestamp();
        } catch (Exception) {
            throw new Exception("Invalid time \"$value\": use a duration like 30m, 2h or 7d, or a date like 2026-09-23 or \"2026-09-23 14:30\" (UTC).");
        }
    }
}
