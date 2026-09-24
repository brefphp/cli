<?php declare(strict_types=1);

namespace Bref\Cli\Test\Commands;

use Bref\Cli\Commands\Logs;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpClient\Response\MockResponse;

class LogsTest extends CommandTestCase
{
    private const LOGS = [
        'from' => '2026-09-23T10:00:00.000Z',
        'to' => '2026-09-23T11:00:00.000Z',
        'limit' => 100,
        'has_more' => false,
        'records' => [
            [
                'timestamp' => '2026-09-23T10:12:51.863Z',
                'function' => 'web',
                'instance' => '45f01a',
                'level' => 'ERROR',
                'message' => 'Payment gateway returned 502',
                'exception' => ['class' => 'RuntimeException', 'message' => 'Payment gateway returned 502', 'file' => 'app/Billing.php:37', 'frames' => 2],
            ],
            [
                'timestamp' => '2026-09-23T10:13:00.000Z',
                'function' => 'web',
                'instance' => '45f01a',
                'level' => 'INFO',
                // Not interpreted as a console style
                'message' => 'Rendered <info>',
            ],
        ],
    ];

    public function test_agents_get_the_logs_as_text_with_a_summary_on_stderr(): void
    {
        $this->runByAnAgent();
        $tester = new CommandTester(new Logs($this->brefCloud([
            '/api/v1/environments/find' => $this->environment(),
            '/api/v1/environments/12/logs' => self::LOGS,
        ])));

        $tester->execute(['--env' => 'prod', '--app' => 'shop', '--team' => 'acme'], ['capture_stderr_separately' => true, 'decorated' => true]);

        $this->assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        $this->assertSame(implode("\n", [
            '2026-09-23 10:12:51.863 web 45f01a ERROR Payment gateway returned 502',
            '    ↳ RuntimeException at app/Billing.php:37 (2 frames)',
            '2026-09-23 10:13:00.000 web 45f01a INFO  Rendered <info>',
            '',
        ]), $tester->getDisplay());
        $this->assertSame("2 lines between 2026-09-23 10:00 and 2026-09-23 11:00 UTC. --full shows long messages in full and stack traces.\n", $tester->getErrorOutput());
        $this->assertSame('/api/v1/environments/find?teamSlug=acme&appName=shop&environmentName=prod', $this->requests[0]);
    }

    public function test_no_logs_in_the_time_range(): void
    {
        $tester = new CommandTester(new Logs($this->brefCloud([
            '/api/v1/environments/find' => $this->environment(),
            '/api/v1/environments/12/logs' => ['records' => []] + self::LOGS,
        ])));

        $tester->execute(['--app' => 'shop', '--team' => 'acme', '--search' => 'payment'], ['capture_stderr_separately' => true]);

        $this->assertSame(0, $tester->getStatusCode(), $tester->getDisplay() . $tester->getErrorOutput());
        $this->assertSame('', $tester->getDisplay());
        $this->assertSame("No logs between 2026-09-23 10:00 and 2026-09-23 11:00 UTC matching the search.\n", $tester->getErrorOutput());
    }

    public function test_more_lines_than_returned(): void
    {
        $tester = new CommandTester(new Logs($this->brefCloud([
            '/api/v1/environments/find' => $this->environment(),
            // Bref Cloud stopped before the limit, at its maximum response size
            '/api/v1/environments/12/logs' => ['has_more' => true] + self::LOGS,
        ])));

        $tester->execute(['--app' => 'shop', '--team' => 'acme', '--full' => true], ['capture_stderr_separately' => true]);

        $this->assertSame(
            "The 2 most recent lines between 2026-09-23 10:00 and 2026-09-23 11:00 UTC, more lines match: narrow with --since, --search or --function.\n",
            $tester->getErrorOutput(),
        );
    }

    public function test_the_options_are_sent_to_bref_cloud(): void
    {
        $tester = new CommandTester(new Logs($this->brefCloud([
            '/api/v1/environments/find' => $this->environment(),
            '/api/v1/environments/12/logs' => self::LOGS,
        ])));

        $tester->execute([
            '--app' => 'shop',
            '--team' => 'acme',
            '--since' => '2026-09-23 10:00',
            '--until' => '2026-09-23 11:00',
            '--search' => 'timeout|memory',
            '--regex' => true,
            '--function' => ['web', 'worker'],
            '--limit' => '20',
            '--all' => true,
            '--full' => true,
        ]);

        $this->assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        $this->assertSame(
            '/api/v1/environments/12/logs?since=1790157600&until=1790161200&search=timeout|memory&regex=1&functions[0]=web&functions[1]=worker&limit=20&all=1&full=1',
            $this->requests[1],
        );
    }

    public function test_json_output_is_the_bref_cloud_response(): void
    {
        $tester = new CommandTester(new Logs($this->brefCloud([
            '/api/v1/environments/find' => $this->environment(),
            '/api/v1/environments/12/logs' => self::LOGS,
        ])));

        $tester->execute(['--app' => 'shop', '--team' => 'acme', '--json' => true]);

        $this->assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        $this->assertSame(self::LOGS, json_decode($tester->getDisplay(), true));
    }

    public function test_json_output_reports_errors_as_json(): void
    {
        $tester = new CommandTester(new Logs($this->brefCloud([
            '/api/v1/environments/find' => $this->environment(),
            '/api/v1/environments/12/logs' => $this->json(
                ['message' => "The function 'api' does not exist in this environment. Available functions: web, worker."],
                422,
            ),
        ])));

        $tester->execute(['--app' => 'shop', '--team' => 'acme', '--function' => ['api'], '--json' => true]);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertSame(
            ['error' => ['message' => "Bref Cloud API error: [422] The function 'api' does not exist in this environment. Available functions: web, worker."]],
            json_decode($tester->getDisplay(), true),
        );
    }

    /**
     * Bref Cloud stops the search when it exceeds its own timeout.
     */
    public function test_a_search_that_takes_too_long_suggests_narrowing_it(): void
    {
        $tester = new CommandTester(new Logs($this->brefCloud([
            '/api/v1/environments/find' => $this->environment(),
            '/api/v1/environments/12/logs' => new MockResponse('{"message":"Service Unavailable"}', ['http_code' => 503]),
        ])));

        $tester->execute(['--app' => 'shop', '--team' => 'acme', '--since' => '30d', '--json' => true]);

        $this->assertSame(
            ['error' => ['message' => 'The log search took too long or failed. Narrow the time range with --since and --until, or filter with --search or --function.']],
            json_decode($tester->getDisplay(), true),
        );
    }

    public function test_without_a_config_file_the_app_and_team_are_required(): void
    {
        $tester = new CommandTester(new Logs($this->brefCloud([])));
        $directory = getcwd();
        chdir(sys_get_temp_dir());

        try {
            $tester->execute(['--app' => 'shop', '--json' => true]);
        } finally {
            chdir((string) $directory);
        }

        $this->assertSame(
            ['error' => ['message' => 'No "bref.php" or "serverless.yml" file in the current directory: run the command in the project directory, or set the application with the --app and --team options.']],
            json_decode($tester->getDisplay(), true),
        );
    }
}
