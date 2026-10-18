<?php

namespace Phattarachai\WorktreePrompt\Tests;

use Closure;
use Phattarachai\WorktreePrompt\Result;
use Phattarachai\WorktreePrompt\Runner;

/**
 * Records every command, and stands in for the programs a CI box doesn't have (npm) with closures.
 */
class FakeRunner extends Runner
{
    /** @var list<list<string>> */
    public array $commands = [];

    /**
     * @param  array<string, Closure(list<string>, string): Result>  $fakes  program => what it does instead
     * @param  array<string, bool>  $available  program => whether `has()` finds it
     */
    public function __construct(public array $fakes = [], public array $available = []) {}

    public function run(array $command, string $cwd, array $env = []): Result
    {
        $this->commands[] = $command;

        return isset($this->fakes[$command[0]])
            ? ($this->fakes[$command[0]])($command, $cwd)
            : parent::run($command, $cwd, $env);
    }

    public function real(array $command, string $cwd): Result
    {
        return parent::run($command, $cwd);
    }

    public function has(string $program): bool
    {
        return $this->available[$program] ?? parent::has($program);
    }

    /**
     * Whether a command starting with these words ran.
     */
    public function ran(string ...$words): bool
    {
        foreach ($this->commands as $command) {
            if (array_slice($command, 0, count($words)) === $words) {
                return true;
            }
        }

        return false;
    }
}
