<?php

namespace Bref\Cli\Tinker;

use Psy\Configuration;
use Psy\Output\ShellOutput;
use Psy\Shell;

class BrefTinkerShell extends Shell
{
    public ShellOutput $rawOutput;
    
    protected string $commandInput;
    
    public function __construct(?Configuration $config = null, string $commandInput = '')
    {
        $this->commandInput = $commandInput;
        
        parent::__construct($config);
    }
    
    public function setRawOutput($rawOutput)
    {
        $this->rawOutput = $rawOutput;
        
        return $this;
    }

    /**
     * Gets the default command loop listeners.
     *
     * @return array An array of Execution Loop Listener instances
     */
    protected function getDefaultLoopListeners(): array
    {
        $listeners = parent::getDefaultLoopListeners();

        $listeners[] = new BrefTinkerLoopListener($this->commandInput);

        return $listeners;
    }

    /**
     * @return list<string>|null
     */
    public function extractContextData(string $output): ?array
    {
        $output = trim($output);
        // First, extract RETURN section if it exists
        if (preg_match('/\[RETURN\](.*?)\[END_RETURN\]/s', $output, $returnMatches)) {
            $returnValue = $returnMatches[1];
            // Remove RETURN section to work with the rest
            $output = (string) preg_replace('/\[RETURN\].*?\[END_RETURN\]/s', '', $output);
        } else {
            $returnValue = '';
        }

        // Then extract CONTEXT section if it exists
        if (preg_match('/\[CONTEXT\](.*?)\[END_CONTEXT\]/s', $output, $contextMatches)) {
            $context = $contextMatches[1];
            // Remove CONTEXT section to get the before part
            $output = (string) preg_replace('/\[CONTEXT\].*?\[END_CONTEXT\]\n?/s', '', $output);
        } else {
            $context = '';
        }

        // Only return null if we couldn't find any meaningful structure
        if (empty($output) && empty($context) && empty($returnValue)) {
            return null;
        }

        return [$output, $context, $returnValue];
    }
}
