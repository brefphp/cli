<?php declare(strict_types=1);

namespace Bref\Cli\Cli;

use Symfony\Component\Console\Formatter\OutputFormatterStyle;

class Styles
{
    private static OutputFormatterStyle $blueFormatter;

    public static function blue(string $text): string
    {
        if (! isset(self::$blueFormatter)) {
            self::$blueFormatter = new OutputFormatterStyle('#3AA9E9');
        }

        return self::$blueFormatter->apply($text);
    }

    public static function brefHeader(): string
    {
        return self::blue('⠷') . self::bold(' bref');
    }

    /**
     * Reset all colors and styles.
     */
    public static function reset(string $text): string
    {
        return "\e[0m{$text}\e[0m";
    }

    public static function bold(string $text): string
    {
        return "\e[1m{$text}\e[22m";
    }

    public static function dim(string $text): string
    {
        return "\e[2m{$text}\e[22m";
    }

    public static function italic(string $text): string
    {
        return "\e[3m{$text}\e[23m";
    }

    public static function underline(string $text): string
    {
        return "\e[4m{$text}\e[24m";
    }

    public static function strikethrough(string $text): string
    {
        return "\e[9m{$text}\e[29m";
    }

    public static function black(string $text): string
    {
        return "\e[30m{$text}\e[39m";
    }

    public static function red(string $text): string
    {
        return "\e[31m{$text}\e[39m";
    }

    public static function green(string $text): string
    {
        return "\e[32m{$text}\e[39m";
    }

    public static function yellow(string $text): string
    {
        return "\e[33m{$text}\e[39m";
    }

    public static function white(string $text): string
    {
        return "\e[37m{$text}\e[39m";
    }

    public static function bgBlack(string $text): string
    {
        return "\e[40m{$text}\e[49m";
    }

    public static function bgRed(string $text): string
    {
        return "\e[41m{$text}\e[49m";
    }

    public static function bgGreen(string $text): string
    {
        return "\e[42m{$text}\e[49m";
    }

    public static function bgYellow(string $text): string
    {
        return "\e[43m{$text}\e[49m";
    }

    public static function bgBlue(string $text): string
    {
        return "\e[44m{$text}\e[49m";
    }

    public static function bgWhite(string $text): string
    {
        return "\e[47m{$text}\e[49m";
    }

    public static function gray(string $text): string
    {
        return "\e[90m{$text}\e[39m";
    }
}