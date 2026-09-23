<?php declare(strict_types=1);

namespace Bref\Cli\Cli;

use Laravel\AgentDetector\AgentDetector;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Who reads the output: a human in a terminal, or a program or an AI agent (Claude Code, Cursor, Codex...).
 */
class OutputMode
{
    public static function isAgent(): bool
    {
        return AgentDetector::detect()->isAgent;
    }

    /**
     * Colors and spinners are for humans in a terminal. An agent may run the CLI in a terminal too.
     */
    public static function isHuman(OutputInterface $output): bool
    {
        return $output->isDecorated() && ! self::isAgent();
    }
}
