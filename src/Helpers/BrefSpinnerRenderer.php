<?php declare(strict_types=1);

namespace Bref\Cli\Helpers;

use Laravel\Prompts\Spinner;
use Laravel\Prompts\Themes\Default\SpinnerRenderer;

class BrefSpinnerRenderer extends SpinnerRenderer
{
    protected array $frames = [
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

    protected string $staticFrame = '⠷';

    public function __invoke(Spinner $spinner): string
    {
        if ($spinner->static) {
            return (string) $this->line(" {$this->brefBlue($this->staticFrame)} {$spinner->message}");
        }

        $spinner->interval = $this->interval;

        $frame = $this->frames[$spinner->count % count($this->frames)];

        return (string) $this->line(" {$this->brefBlue($frame)} {$spinner->message}");
    }

    public function brefBlue(string $text): string
    {
        return Styles::blue($text);
    }
}
