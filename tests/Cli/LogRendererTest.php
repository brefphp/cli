<?php declare(strict_types=1);

namespace Bref\Cli\Test\Cli;

use Bref\Cli\Cli\LogRenderer;
use PHPUnit\Framework\TestCase;

class LogRendererTest extends TestCase
{
    private const ERROR = [
        'timestamp' => '2026-09-23T10:12:51.863Z',
        'function' => 'web',
        'instance' => '45f01a',
        'level' => 'ERROR',
        'message' => 'Payment gateway returned 502',
        'exception' => [
            'class' => 'RuntimeException',
            'message' => 'Payment gateway returned 502',
            'file' => 'app/Billing.php:37',
            'frames' => 2,
        ],
    ];

    public function test_one_line_per_record_with_exceptions_summarized(): void
    {
        $lines = (new LogRenderer(colors: false, full: false))->render([
            self::ERROR,
            [
                'timestamp' => '2026-09-23T10:13:33.169Z',
                'function' => 'jobsWorker',
                'instance' => '382fc3',
                'level' => 'INFO',
                'message' => "Import failed:\nline 12: invalid email",
                'context' => ['user_id' => 42, 'url' => 'https://example.com/a'],
            ],
            [
                'timestamp' => '2026-09-23T10:14:00.000Z',
                'function' => 'artisan',
                'instance' => '0f9e8d',
                'level' => null,
                'message' => 'Done.',
            ],
        ]);

        $this->assertSame([
            "2026-09-23 10:12:51.863 web        45f01a ERROR Payment gateway returned 502\n"
            . '    ↳ RuntimeException at app/Billing.php:37 (2 frames)',
            "2026-09-23 10:13:33.169 jobsWorker 382fc3 INFO  Import failed:\n"
            . '    line 12: invalid email {"user_id":42,"url":"https://example.com/a"}',
            '2026-09-23 10:14:00.000 artisan    0f9e8d       Done.',
        ], $lines);
    }

    /**
     * Only Bref's Monolog formatter gives a level: an app that does not use it gets no empty column.
     */
    public function test_no_level_column_when_no_line_has_a_level(): void
    {
        $lines = (new LogRenderer(colors: false, full: false))->render([
            ['timestamp' => '2026-09-23T10:14:00.000Z', 'function' => 'web', 'instance' => '0f9e8d', 'level' => null, 'message' => 'START processing batch 12 of 40'],
        ]);

        $this->assertSame(['2026-09-23 10:14:00.000 web 0f9e8d START processing batch 12 of 40'], $lines);
    }

    public function test_the_causes_of_an_exception_are_shown_with_their_message(): void
    {
        $record = self::ERROR;
        $record['exception']['previous'] = [
            'class' => 'GuzzleHttp\Exception\ServerException',
            'message' => '502 Bad Gateway',
            'file' => 'vendor/guzzlehttp/guzzle/src/Middleware.php:69',
            'frames' => 0,
        ];

        $lines = (new LogRenderer(colors: false, full: false))->render([$record]);

        $this->assertSame([implode("\n", [
            '2026-09-23 10:12:51.863 web 45f01a ERROR Payment gateway returned 502',
            '    ↳ RuntimeException at app/Billing.php:37 (2 frames)',
            '    ↳ Caused by GuzzleHttp\Exception\ServerException: 502 Bad Gateway',
        ])], $lines);
    }

    public function test_full_records_show_the_stack_trace_and_the_previous_exceptions(): void
    {
        $record = self::ERROR;
        $record['exception']['trace'] = ['app/Http/Controllers/CheckoutController.php:21', 'vendor/laravel/framework/src/Illuminate/Routing/Route.php:254'];
        $record['exception']['previous'] = [
            'class' => 'GuzzleHttp\Exception\ServerException',
            'message' => '502 Bad Gateway',
            'file' => 'vendor/guzzlehttp/guzzle/src/Middleware.php:69',
            'frames' => 0,
            'trace' => [],
        ];

        $lines = (new LogRenderer(colors: false, full: true))->render([$record]);

        $this->assertSame([implode("\n", [
            '2026-09-23 10:12:51.863 web 45f01a ERROR Payment gateway returned 502',
            '    ↳ RuntimeException: Payment gateway returned 502',
            '      at app/Billing.php:37',
            '      #0 app/Http/Controllers/CheckoutController.php:21',
            '      #1 vendor/laravel/framework/src/Illuminate/Routing/Route.php:254',
            '    ↳ Caused by GuzzleHttp\Exception\ServerException: 502 Bad Gateway',
            '      at vendor/guzzlehttp/guzzle/src/Middleware.php:69',
        ])], $lines);
    }

    public function test_an_exception_without_file(): void
    {
        $lines = (new LogRenderer(colors: false, full: false))->render([[
            'timestamp' => '2026-09-23T10:13:24.398Z',
            'function' => 'web',
            'instance' => '45f01a',
            'level' => 'ERROR',
            'message' => 'The request timed out after 26999 ms.',
            // A Bref runtime error
            'exception' => ['class' => 'Bref\FpmRuntime\FastCgi\Timeout', 'message' => 'The request timed out after 26999 ms.', 'file' => '', 'frames' => 6],
        ]]);

        $this->assertSame(["2026-09-23 10:13:24.398 web 45f01a ERROR The request timed out after 26999 ms.\n    ↳ Bref\FpmRuntime\FastCgi\Timeout (6 frames)"], $lines);
    }

    public function test_long_contexts_are_truncated_unless_full(): void
    {
        $record = [
            'timestamp' => '2026-09-23T10:14:00.000Z',
            'function' => 'web',
            'instance' => '0f9e8d',
            'level' => 'INFO',
            'message' => 'Request',
            'context' => ['body' => str_repeat('x', 600)],
        ];

        $truncated = (new LogRenderer(colors: false, full: false))->render([$record])[0];
        $full = (new LogRenderer(colors: false, full: true))->render([$record])[0];

        $this->assertStringEndsWith('xxx…(+111 chars)', $truncated);
        $this->assertStringEndsWith(str_repeat('x', 600) . '"}', $full);
    }

    public function test_colors_are_only_added_on_request(): void
    {
        $colored = (new LogRenderer(colors: true, full: false))->render([self::ERROR])[0];

        $this->assertStringContainsString("\e[31mERROR\e[39m", $colored);
        $this->assertStringNotContainsString("\e[", (new LogRenderer(colors: false, full: false))->render([self::ERROR])[0]);
    }
}
