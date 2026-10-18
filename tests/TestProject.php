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
