<?php declare(strict_types=1);

namespace Bref\Cli\Test\Cli;

use Bref\Cli\Cli\IO;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * `IO` writes its own decorations (spinner, verbose logs, warnings, errors) using raw ANSI escape
 * codes (see `Styles`), not Symfony formatter tags. Symfony only strips formatter tags when the
 * output is not decorated, so `IO` has to strip its own raw codes itself, otherwise a piped or
 * redirected output (or CI) gets polluted with escape codes.
 */
class IOTest extends TestCase
{
    protected function tearDown(): void
    {
        IO::stop();
    }

    public function test_warnings_and_errors_have_no_escape_codes_when_not_decorated(): void
    {
        $output = new BufferedOutput(decorated: false);
        IO::init(new ArrayInput([]), $output);

        IO::warning('Something to pay attention to');
        IO::error(new RuntimeException('The command failed'));

        $display = $output->fetch();

        $this->assertStringNotContainsString("\e[", $display);
        $this->assertStringContainsString('Something to pay attention to', $display);
        $this->assertStringContainsString('The command failed', $display);
    }

    public function test_warnings_and_errors_keep_colors_when_decorated(): void
    {
        $output = new BufferedOutput(decorated: true);
        IO::init(new ArrayInput([]), $output);

        IO::warning('Something to pay attention to');

        $this->assertStringContainsString("\e[", $output->fetch());
    }

    public function test_the_spinner_has_no_escape_codes_when_not_decorated(): void
    {
        $output = new BufferedOutput(decorated: false);
        IO::init(new ArrayInput([]), $output);

        IO::spin('starting command');
        IO::spin('running');
        IO::spinClear();

        $display = $output->fetch();

        $this->assertStringNotContainsString("\e[", $display);
        $this->assertStringContainsString('starting command', $display);
        $this->assertStringContainsString('running', $display);
    }

    public function test_the_spinner_keeps_colors_when_decorated(): void
    {
        $output = new BufferedOutput(decorated: true);
        IO::init(new ArrayInput([]), $output);

        IO::spin('starting command');
        IO::spinClear();

        $this->assertStringContainsString("\e[", $output->fetch());
    }

    /**
     * Verbose mode is forced on for non-interactive environments (CI, a piped output...), so its
     * lines are exactly the kind of decoration that must stay clean when not decorated.
     */
    public function test_verbose_logs_have_no_escape_codes_when_not_decorated(): void
    {
        $output = new BufferedOutput(decorated: false);
        IO::init(new ArrayInput([]), $output);

        IO::verbose('Diagnostic detail');

        $display = $output->fetch();

        $this->assertStringNotContainsString("\e[", $display);
        $this->assertStringContainsString('Diagnostic detail', $display);
    }

    /**
     * The command's own output (e.g. what `bref command` prints from the deployed application)
     * is never styled by the CLI, and must stay exactly as it is regardless of decoration.
     */
    public function test_plain_writeln_is_untouched(): void
    {
        $output = new BufferedOutput(decorated: false);
        IO::init(new ArrayInput([]), $output);

        IO::writeln('Hello from the deployed application');

        $this->assertSame("Hello from the deployed application\n", $output->fetch());
    }
}
