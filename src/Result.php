<?php

namespace Phattarachai\WorktreePrompt;

final readonly class Result
{
    public function __construct(
        public int $exitCode,
        public string $output,
        public string $errorOutput = '',
    ) {}

    public function successful(): bool
    {
        return $this->exitCode === 0;
    }

    public function failed(): bool
    {
        return ! $this->successful();
    }

    /**
     * Stdout and stderr together — git, composer and npm write progress and most messages to stderr.
     */
    public function allOutput(): string
    {
        return trim($this->output."\n".$this->errorOutput);
    }
}
