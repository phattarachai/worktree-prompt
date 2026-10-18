<?php

namespace Phattarachai\WorktreePrompt;

use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Every external program lwt starts (git, composer, npm, cp, pg_dump) goes through here,
 * so tests can record the commands and stand in for the ones a CI box doesn't have.
 */
class Runner
{
    public static ?bool $interactive = null;

    /**
     * @param  list<string>  $command
     * @param  array<string, string>  $env  added to (and overriding) lwt's own environment
     */
    public function run(array $command, string $cwd, array $env = []): Result
    {
        $process = new Process($command, $cwd, $env, timeout: null);
        $process->run();

        return new Result((int) $process->getExitCode(), $process->getOutput(), $process->getErrorOutput());
    }

    /**
     * A shell pipeline, for the one place a pipe is the point: dump one database straight into another.
     *
     * @param  array<string, string>  $env
     */
    public function shell(string $script, string $cwd, array $env = []): Result
    {
        return $this->run(['bash', '-o', 'pipefail', '-c', $script], $cwd, $env);
    }

    public function has(string $program): bool
    {
        return (new ExecutableFinder)->find($program) !== null;
    }

    public static function interactive(): bool
    {
        return self::$interactive ??= stream_isatty(STDIN) && stream_isatty(STDOUT);
    }
}
