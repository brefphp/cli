<?php declare(strict_types=1);

namespace Bref\Cli\Helpers;

use Symfony\Component\Console\Helper\ProgressIndicator;
use Symfony\Component\Console\Output\OutputInterface;

class BrefSpinner extends ProgressIndicator
{
    public function __construct(OutputInterface $output)
    {
        $frames = [
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

        parent::__construct($output, indicatorValues: $frames);
    }
}