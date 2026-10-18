<?php

namespace Phattarachai\WorktreePrompt;

use Composer\InstalledVersions;
use Phattarachai\WorktreePrompt\Commands\Add;
use Phattarachai\WorktreePrompt\Commands\Command;
use Phattarachai\WorktreePrompt\Commands\ListWorktrees;
use Phattarachai\WorktreePrompt\Commands\Remove;
use Phattarachai\WorktreePrompt\Commands\Setup;
use RuntimeException;

use function Laravel\Prompts\error;

class Application
{
    /**
     * @var array<string, array{0: class-string<Command>, 1: string, 2: string}>
     */
    private const array COMMANDS = [
        'add' => [Add::class, '[branch]', 'New worktree (fetch, branch, folder), then set it up'],
        'setup' => [Setup::class, '[path]', 'Set up a worktree that already exists (default: this one)'],
        'remove' => [Remove::class, '[path|branch]', 'Remove a clean worktree, its database and site; offer to delete the branch'],
        'list' => [ListWorktrees::class, '', 'Every worktree, and whether it is set up'],
    ];

    public function __construct(private readonly ?Runner $runner = null) {}

    /**
     * @param  list<string>  $arguments  argv without the script name
     */
    public function run(array $arguments, string $cwd): int
    {
        if (array_intersect(['-n', '--no-interaction'], $arguments) !== []) {
            Runner::$interactive = false;
            $arguments = array_values(array_diff($arguments, ['-n', '--no-interaction']));
        }

        $command = $arguments[0] ?? '';

        if (in_array($command, ['-V', '--version'], true)) {
            echo 'worktree-prompt '.self::version().PHP_EOL;

            return 0;
        }

        if (! isset(self::COMMANDS[$command])) {
            $this->help();

            return in_array($command, ['', '-h', '--help', 'help'], true) ? 0 : 1;
        }

        try {
            $class = self::COMMANDS[$command][0];

            return (new $class($this->runner ?? new Runner, $cwd))(array_slice($arguments, 1));
        } catch (RuntimeException $exception) {
            error($exception->getMessage());

            return 1;
        }
    }

    private function help(): void
    {
        $lines = [
            'worktree-prompt '.self::version().' — a git worktree of a Laravel project, ready to run.',
            '',
            'Usage:',
        ];

        foreach (self::COMMANDS as $name => [, $arguments, $description]) {
            $lines[] = sprintf('  lwt %-22s %s', trim("{$name} {$arguments}"), $description);
        }

        array_push(
            $lines,
            '',
            'Setup options (add and setup; each one not given is asked when there is a terminal):',
            '  --db=shared|empty|copy     Database: the main checkout\'s (default), a new empty one, or a copy',
            '  --seed                     Seed the new empty database',
            '  --db-admin=<user>          Create (and drop) it as root/postgres when the app\'s user may not; LWT_DB_ADMIN_PASSWORD',
            '  --url=<url>                APP_URL (SESSION_DOMAIN and *_URL keys on the same host follow)',
            '  --npm, --node=auto|copy|install   node_modules: copy when the lockfile matches, else a clean install',
            '  --build                    npm run build (implies --npm)',
            '  --vendor=auto|copy|install vendor: a real copy when composer.lock matches (default), never a symlink',
            '  --env=copy|symlink         .env: a copy (default), or a symlink to the main checkout\'s',
            '',
            'add:     --base=<ref> (default origin/HEAD) --path=<folder> (default ../<app>-<branch>) --no-fetch',
            'remove:  --delete-branch | --keep-branch   --drop-db | --keep-db   --db-admin=<user>',
            '-n, --no-interaction   No prompts: the defaults and the flags only (what an agent should use)',
        );

        echo implode(PHP_EOL, $lines).PHP_EOL;
    }

    private static function version(): string
    {
        $package = 'phattarachai/worktree-prompt';

        if (! InstalledVersions::isInstalled($package) || InstalledVersions::getRootPackage()['name'] === $package) {
            return 'dev';
        }

        return (string) InstalledVersions::getPrettyVersion($package);
    }
}
