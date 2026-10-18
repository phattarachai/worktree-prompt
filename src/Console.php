<?php

namespace Phattarachai\WorktreePrompt;

use Phattarachai\WorktreePrompt\Prompts\Output;
use Symfony\Component\Console\Formatter\OutputFormatter;

use function Laravel\Prompts\spin;

/**
 * Runs commands in front of the user: each one is echoed before it runs, so the setup
 * shows the steps a reader would otherwise type by hand.
 */
class Console
{
    private const int FAILURE_LINES = 20;

    public function __construct(public readonly Runner $runner) {}

    /**
     * @param  list<string>  $command
     * @param  array<string, string>  $env
     */
    public function run(array $command, string $cwd, ?string $spinner = null, array $env = []): Result
    {
        $this->line('<fg=gray>  → '.OutputFormatter::escape(self::describe($command)).'</>');

        $result = $spinner !== null && Runner::interactive()
            ? spin(fn () => $this->runner->run($command, $cwd, $env), $spinner)
            : $this->runner->run($command, $cwd, $env);

        if ($result->failed()) {
            $this->tail($result->allOutput());
        }

        return $result;
    }

    /**
     * @param  array<string, string>  $env
     */
    public function shell(string $script, string $cwd, string $shown, ?string $spinner = null, array $env = []): Result
    {
        $this->line('<fg=gray>  → '.OutputFormatter::escape($shown).'</>');

        $result = $spinner !== null && Runner::interactive()
            ? spin(fn () => $this->runner->shell($script, $cwd, $env), $spinner)
            : $this->runner->shell($script, $cwd, $env);

        if ($result->failed()) {
            $this->tail($result->allOutput());
        }

        return $result;
    }

    public function done(string $message): void
    {
        $this->line("  <fg=green>✓</> {$message}");
    }

    public function skip(string $message): void
    {
        $this->line("  <fg=gray>· {$message}</>");
    }

    public function warn(string $message): void
    {
        $this->line("  <fg=yellow>!</> {$message}");
    }

    public function line(string $message = ''): void
    {
        Output::writeln(self::home($message));
    }

    /**
     * `cd <path>` the user can paste: `~/www/app-login` when nothing in it needs quoting.
     */
    public static function cd(string $path): string
    {
        $short = self::home($path);

        if (preg_match('#^~?[\w./-]+$#', $short) === 1) {
            return "cd {$short}";
        }

        return str_starts_with($short, '~/') ? 'cd ~/'.escapeshellarg(substr($short, 2)) : 'cd '.escapeshellarg($path);
    }

    /**
     * Paths under the home folder, shortened to `~/…` for reading.
     */
    private static function home(string $text): string
    {
        $home = rtrim((string) getenv('HOME'), '/');

        return $home === '' ? $text : str_replace($home.'/', '~/', $text);
    }

    /**
     * @param  list<string>  $command
     */
    public static function describe(array $command): string
    {
        return implode(' ', array_map(
            fn (string $part) => preg_match('/^[\w.\/:=@%+,-]+$/', $part) === 1 ? $part : escapeshellarg($part),
            $command,
        ));
    }

    private function tail(string $output): void
    {
        $lines = array_slice(explode("\n", $output), -self::FAILURE_LINES);

        foreach ($lines as $line) {
            $this->line('<fg=gray>    '.OutputFormatter::escape(rtrim($line)).'</>');
        }
    }
}
