<?php declare(strict_types=1);

namespace Bref\Cli\Test\Cli;

use Bref\Cli\Cli\Styles;
use PHPUnit\Framework\TestCase;

class StylesTest extends TestCase
{
    public function test_strip_removes_ansi_escape_codes(): void
    {
        $this->assertSame(
            'bold red gray plain',
            Styles::strip(Styles::bold('bold') . ' ' . Styles::red('red') . ' ' . Styles::gray('gray') . ' plain'),
        );
    }

    public function test_strip_leaves_text_without_escape_codes_untouched(): void
    {
        $this->assertSame('plain text', Styles::strip('plain text'));
    }
}
