<?php declare(strict_types=1);

namespace Bref\Cli\Cli;

class OpenUrl
{
    public static function open(string $url): void
    {
        switch (php_uname('s')) {
            case 'Darwin':
                exec('open ' . escapeshellarg($url));
                break;
            case 'Windows':
                exec('start ' . escapeshellarg($url));
                break;
            default:
                if (exec('which xdg-open')) {
                    exec('xdg-open ' . escapeshellarg($url));
                }
                break;
        }
    }
}