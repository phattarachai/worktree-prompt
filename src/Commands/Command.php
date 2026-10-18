<?php

namespace Phattarachai\WorktreePrompt\Commands;

use Phattarachai\WorktreePrompt\Console;
use Phattarachai\WorktreePrompt\Database;
use Phattarachai\WorktreePrompt\EnvFile;
use Phattarachai\WorktreePrompt\Git;
use Phattarachai\WorktreePrompt\Runner;
use Phattarachai\WorktreePrompt\SetupOptions;
use Phattarachai\WorktreePrompt\UserError;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

abstract class Command
{
    protected readonly Console $console;

    protected readonly Git $git;

    public function __construct(protected readonly Runner $runner, protected readonly string $cwd)
    {
        $this->console = new Console($runner);
        $this->git = new Git($runner, $cwd);
    }

    /**
     * @param  list<string>  $args
     */
    abstract public function __invoke(array $args): int;

    protected function mainCheckout(): string
    {
        if (! $this->git->isRepository()) {
            throw new UserError('Not inside a git repository.');
        }

        return $this->git->mainCheckout();
    }

    /**
     * The setup flags, and a prompt for each one not given when there is a terminal.
     *
     * @param  list<string>  $args
     */
    protected function setupOptions(array &$args, string $main, string $path, string $suggestedDatabase): SetupOptions
    {
        $node = self::flag($args, '--npm') ? 'auto' : self::option($args, 'node');
        $build = self::flag($args, '--build');
        $url = self::option($args, 'url');
        $database = self::option($args, 'db');

        $options = new SetupOptions(
            vendor: self::option($args, 'vendor') ?? 'auto',
            env: self::option($args, 'env') ?? 'copy',
            url: $url === '' ? null : $url,
            database: $database ?? 'shared',
            seed: self::flag($args, '--seed'),
            node: $node ?? 'skip',
            build: $build,
            databaseAdmin: self::option($args, 'db-admin'),
        );

        if ($args !== []) {
            throw new UserError('Unknown option: '.implode(' ', $args).' — see lwt --help.');
        }

        if (! Runner::interactive()) {
            return $options->validate();
        }

        $source = Database::fromEnv(EnvFile::read("{$main}/.env"), $main);

        if ($database === null && $options->env === 'copy' && $source !== null) {
            $options->database = (string) select('Database', [
                'shared' => "Shared — the main checkout's {$source->database}",
                'copy' => "Its own, a copy of {$source->database} (migrations then run on it)",
                'empty' => 'Its own, empty — php artisan migrate',
            ], default: $suggestedDatabase, hint: $suggestedDatabase === 'copy' ? 'This branch changes database/migrations.' : 'Pick its own when the branch adds or changes migrations.');

            if ($options->database === 'empty') {
                $options->seed = confirm('Seed it too?', default: false);
            }
        }

        if ($url === null && $options->env === 'copy') {
            $suggested = dirname($path) === dirname($main) ? 'http://'.strtolower(basename($path)).'.test' : '';
            $current = EnvFile::read("{$main}/.env")->get('APP_URL') ?? '';
            $answer = trim(text('APP_URL', default: $suggested, hint: "Empty keeps the main checkout's ({$current})."));
            $options->url = $answer === '' ? null : $answer;
        }

        if ($node === null && is_file("{$main}/package.json")) {
            $options->node = (string) select('node_modules', [
                'skip' => 'Skip — backend work and tests don\'t need it',
                'auto' => 'Copy the main checkout\'s (clean install when the lockfile differs)',
                'install' => 'Clean install',
            ], default: 'skip');

            if ($options->node !== 'skip' && ! $build) {
                $options->build = confirm('Build the assets (npm run build)?', default: false);
            }
        }

        return $options->validate();
    }

    /**
     * @param  list<string>  $args
     */
    protected static function flag(array &$args, string ...$flags): bool
    {
        $found = array_intersect($flags, $args) !== [];
        $args = array_values(array_filter($args, fn (string $arg) => ! in_array($arg, $flags, true)));

        return $found;
    }

    /**
     * `--name=value` or `--name value`, removed from the arguments.
     *
     * @param  list<string>  $args
     */
    protected static function option(array &$args, string $name): ?string
    {
        foreach ($args as $index => $arg) {
            if (str_starts_with($arg, "--{$name}=")) {
                array_splice($args, $index, 1);

                return substr($arg, strlen($name) + 3);
            }

            if ($arg === "--{$name}" && isset($args[$index + 1])) {
                $value = $args[$index + 1];
                array_splice($args, $index, 2);

                return $value;
            }
        }

        return null;
    }

    /**
     * The first argument that isn't an option, removed from the arguments.
     *
     * @param  list<string>  $args
     */
    protected static function argument(array &$args): ?string
    {
        foreach ($args as $index => $arg) {
            if (! str_starts_with($arg, '-')) {
                array_splice($args, $index, 1);

                return $arg;
            }
        }

        return null;
    }

    protected function absolute(string $path): string
    {
        $path = str_starts_with($path, '~/') ? getenv('HOME').substr($path, 1) : $path;
        $path = str_starts_with($path, '/') ? $path : "{$this->cwd}/{$path}";
        $parts = [];

        foreach (explode('/', $path) as $part) {
            match ($part) {
                '', '.' => null,
                '..' => array_pop($parts),
                default => $parts[] = $part,
            };
        }

        return '/'.implode('/', $parts);
    }

    /**
     * A folder- and database-safe slug of a branch: its last segment, `fix/login-redirect` → `login-redirect`.
     */
    protected static function slug(string $branch): string
    {
        $last = basename(str_replace('\\', '/', $branch));

        return trim((string) preg_replace('/[^a-z0-9._-]+/', '-', strtolower($last)), '-.') ?: 'worktree';
    }
}
