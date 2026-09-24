<?php declare(strict_types=1);

namespace Bref\Cli\Test\Cli;

use Bref\Cli\Cli\TimeRange;
use Exception;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class TimeRangeTest extends TestCase
{
    // 2026-09-23 11:00:00 UTC
    private const NOW = 1790161200;

    #[DataProvider('times')]
    public function test_parses_durations_and_dates(string $value, string $expected): void
    {
        $this->assertSame($expected, gmdate('Y-m-d H:i:s', TimeRange::parse($value, self::NOW)));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function times(): array
    {
        return [
            'seconds' => ['30s', '2026-09-23 10:59:30'],
            'minutes' => ['30m', '2026-09-23 10:30:00'],
            'hours' => ['2h', '2026-09-23 09:00:00'],
            'days' => ['7d', '2026-09-16 11:00:00'],
            'weeks' => ['1w', '2026-09-16 11:00:00'],
            'date, in UTC' => ['2026-09-20', '2026-09-20 00:00:00'],
            'datetime, in UTC' => ['2026-09-20 14:30', '2026-09-20 14:30:00'],
            'datetime with an offset' => ['2026-09-20T14:30:00+02:00', '2026-09-20 12:30:00'],
        ];
    }

    public function test_an_invalid_time_is_explained(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Invalid time "yesterdayish": use a duration like 30m, 2h or 7d, or a date like 2026-09-23 or "2026-09-23 14:30" (UTC).');

        TimeRange::parse('yesterdayish', self::NOW);
    }
}
