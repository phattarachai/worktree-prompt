<?php

namespace Phattarachai\WorktreePrompt\Tests;

use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * A Laravel-shaped project (artisan, composer.json with App\, a real vendor) cloned from a bare
 * "origin", in a throwaway folder: `<root>/origin.git` and the main checkout `<root>/app`.
 */
final class TestProject
{
    /** @var list<string> */
    private static array $roots = [];

    private function __construct(public readonly string $root, public readonly string $path) {}

    public static function create(): self
    {
        $root = (string) realpath(sys_get_temp_dir()).'/lwt-'.bin2hex(random_bytes(5));
        mkdir($root);
        self::$roots[] = $root;

        self::exec($root, ['git', 'init', '--quiet', '--bare', '--initial-branch=main', 'origin.git']);
        self::exec($root, ['git', 'clone', '--quiet', 'origin.git', 'app']);

        $project = new self($root, "{$root}/app");
        self::exec($root, ['cp', '-R', __DIR__.'/fixtures/app/.', $project->path]);
        rename("{$project->path}/gitignore", "{$project->path}/.gitignore");
        $project->write('.env', "APP_NAME=Fixture\nAPP_ENV=local\nAPP_URL=http://fixture.test\nSESSION_DOMAIN=fixture.test\nDB_CONNECTION=sqlite\n");
        $project->write('.env.testing', "DB_CONNECTION=pgsql\nDB_DATABASE=fixture_testing\n");
        $project->write('README.md', "fixture\n");
        $project->write('database/migrations/.gitkeep', '');

        self::vendor($project->path);

        $project->git('config', 'user.name', 'Test');
        $project->git('config', 'user.email', 'test@example.com');
        $project->git('switch', '--quiet', '-c', 'main');
        $project->git('add', '--all');
        $project->git('commit', '--quiet', '-m', 'Initial commit');
        $project->git('push', '--quiet', '-u', 'origin', 'main');
        $project->git('remote', 'set-head', 'origin', 'main');

        return $project;
    }

    /**
     * `git worktree add -b <branch> <root>/<folder> origin/main`, not set up.
     */
    public function worktree(string $branch, ?string $folder = null): string
    {
        $path = $this->root.'/'.($folder ?? 'app-'.basename($branch));
        is_dir(dirname($path)) || mkdir(dirname($path), recursive: true);
        $this->git('worktree', 'add', '--quiet', '--no-track', '-b', $branch, $path, 'origin/main');

        return $path;
    }

    /**
     * A branch pushed to origin from another clone, which this checkout has only as `origin/<branch>` after a fetch.
     */
    public function pushFromElsewhere(string $branch, string $file, string $contents): void
    {
        $other = "{$this->root}/other-".bin2hex(random_bytes(3));
        self::exec($this->root, ['git', 'clone', '--quiet', 'origin.git', basename($other)]);
        self::exec($other, ['git', 'switch', '--quiet', '-c', $branch]);
        file_put_contents("{$other}/{$file}", $contents);
        self::exec($other, ['git', 'add', '--all']);
        self::exec($other, ['git', '-c', 'user.name=T', '-c', 'user.email=t@example.com', 'commit', '--quiet', '-m', "Add {$file}"]);
        self::exec($other, ['git', 'push', '--quiet', 'origin', $branch]);
    }

    /**
     * A submodule at `$path`, committed and pushed, from a bare `<root>/<name>.git` of its own.
     */
    public function submodule(string $path = 'lib', string $name = 'lib', ?string $in = null): void
    {
        $in ??= $this->path;
        self::repository($this->root, $name);
        self::exec($in, ['git', '-c', 'protocol.file.allow=always', 'submodule', '--quiet', 'add', "{$this->root}/{$name}.git", $path]);
        self::exec($in, ['git', '-c', 'user.name=T', '-c', 'user.email=t@example.com', 'commit', '--quiet', '-m', "Add {$path}"]);
        self::exec($in, ['git', 'push', '--quiet', 'origin', 'HEAD']);
    }

    /**
     * `git submodule update --init --recursive`: a worktree starts with its submodules empty.
     */
    public static function initSubmodules(string $worktree): void
    {
        self::exec($worktree, ['git', '-c', 'protocol.file.allow=always', 'submodule', '--quiet', 'update', '--init', '--recursive']);
    }

    /**
     * A commit in any checkout, whoever its configured author is.
     */
    public static function commit(string $checkout, string $file, string $contents = "work\n"): void
    {
        file_put_contents("{$checkout}/{$file}", $contents);
        self::exec($checkout, ['git', 'add', '--all']);
        self::exec($checkout, ['git', '-c', 'user.name=T', '-c', 'user.email=t@example.com', 'commit', '--quiet', '-m', "Add {$file}"]);
    }

    /**
     * A bare `<root>/<name>.git` with one commit on main.
     */
    private static function repository(string $root, string $name): void
    {
        self::exec($root, ['git', 'init', '--quiet', '--bare', '--initial-branch=main', "{$name}.git"]);
        self::exec($root, ['git', 'clone', '--quiet', "{$name}.git", "{$name}-seed"]);
        self::exec("{$root}/{$name}-seed", ['git', 'switch', '--quiet', '-c', 'main']);
        self::commit("{$root}/{$name}-seed", 'lib.txt', "lib\n");
        self::exec("{$root}/{$name}-seed", ['git', 'push', '--quiet', 'origin', 'main']);
    }

    /**
     * A post-checkout hook shared by every worktree, that prints something and exits with `$exitCode`.
     */
    public function postCheckoutHook(int $exitCode): void
    {
        chmod($this->write('.git/hooks/post-checkout', "#!/bin/sh\necho 'sync: hook went wrong' >&2\nexit {$exitCode}\n"), 0755);
    }

    public function git(string ...$arguments): string
    {
        return self::exec($this->path, ['git', ...$arguments]);
    }

    public function write(string $file, string $contents, ?string $in = null): string
    {
        $target = ($in ?? $this->path).'/'.$file;
        is_dir(dirname($target)) || mkdir(dirname($target), recursive: true);
        file_put_contents($target, $contents);

        return $target;
    }

    /**
     * `composer install` once per run, then a copy: the fixture's vendor never changes.
     */
    private static function vendor(string $path): void
    {
        static $built = null;

        if ($built === null) {
            $built = (string) realpath(sys_get_temp_dir()).'/lwt-vendor-'.getmypid();
            self::exec(sys_get_temp_dir(), ['rm', '-rf', $built]);
            self::exec(sys_get_temp_dir(), ['cp', '-R', $path, $built]);
            self::exec($built, ['composer', 'install', '--quiet', '--no-interaction']);
            register_shutdown_function(fn () => (new Process(['rm', '-rf', $built]))->run());
        }

        self::exec($path, ['cp', '-R', "{$built}/vendor", "{$built}/composer.lock", $path]);
    }

    public static function cleanUp(): void
    {
        foreach (self::$roots as $root) {
            (new Process(['rm', '-rf', $root]))->run();
        }

        self::$roots = [];
    }

    /**
     * @param  list<string>  $command
     */
    public static function exec(string $cwd, array $command): string
    {
        $process = new Process($command, $cwd, timeout: 120);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException(implode(' ', $command).' failed: '.$process->getErrorOutput().$process->getOutput());
        }

        return trim($process->getOutput());
    }
}
