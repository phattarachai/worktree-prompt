<?php

namespace Phattarachai\WorktreePrompt\Commands;

use PDOException;
use Phattarachai\WorktreePrompt\Console;
use Phattarachai\WorktreePrompt\Database;
use Phattarachai\WorktreePrompt\EnvFile;
use Phattarachai\WorktreePrompt\Git;
use Phattarachai\WorktreePrompt\Runner;
use Phattarachai\WorktreePrompt\State;
use Phattarachai\WorktreePrompt\UserError;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\select;

/**
 * `lwt remove [path|branch]`: the whole cleanup, not just the folder. Refuses a dirty worktree; drops the
 * database lwt made; offers to delete the branch; prunes; removes an emptied parent folder.
 */
class Remove extends Command
{
    private const string USAGE = 'Without a terminal: lwt remove <path|branch> [--delete-branch] [--drop-db] -n';

    public function __invoke(array $args): int
    {
        $main = $this->mainCheckout();
        $mainGit = new Git($this->runner, $main);
        $target = self::argument($args);
        $deleteBranch = self::flag($args, '--delete-branch') ? true : (self::flag($args, '--keep-branch') ? false : null);
        $dropDatabase = self::flag($args, '--drop-db') ? true : (self::flag($args, '--keep-db') ? false : null);
        $admin = self::option($args, 'db-admin');

        if ($args !== []) {
            throw new UserError('Unknown option: '.implode(' ', $args).' — see lwt --help.');
        }

        $worktree = $this->pick($mainGit, $target);
        $path = $worktree['path'];
        $branch = $worktree['branch'];

        if ($worktree['locked']) {
            throw new UserError("{$path} is locked — git worktree unlock it first.");
        }

        $git = new Git($this->runner, $path);
        $missing = ! is_dir($path);

        if (! $missing && ($dirty = $git->status()) !== []) {
            throw new UserError("{$path} has ".count($dirty).' uncommitted or untracked file(s) — commit or delete them first (git -C '.escapeshellarg($path).' status).');
        }

        $state = $missing ? [] : State::read($git);
        $env = EnvFile::read(is_file("{$path}/.env") ? "{$path}/.env" : "{$main}/.env");
        $database = $this->databaseToDrop($state, $env, $main, $path, $dropDatabase)?->administeredBy($admin ?? $state['database']['admin'] ?? null);

        $removed = $missing
            ? $this->console->run(['git', 'worktree', 'prune'], $main)
            : $this->console->run(['git', 'worktree', 'remove', $path], $main);

        if ($removed->failed()) {
            return 1;
        }

        $this->console->done("worktree — removed {$path}");

        if ($database !== null) {
            $this->drop($database);
        }

        if ($branch !== null) {
            $this->deleteBranch($mainGit, $branch, $state['base'] ?? null, $deleteBranch);
        }

        $this->console->run(['git', 'worktree', 'prune'], $main);
        $this->removeEmptyParent($path, $main);

        if (str_starts_with($this->cwd.'/', $path.'/')) {
            $this->console->line();
            $this->console->line('  Your shell is in the removed folder: '.Console::cd($main));
        }

        return 0;
    }

    /**
     * @return array{path: string, branch: ?string, head: string, bare: bool, locked: bool, prunable: bool}
     */
    private function pick(Git $git, ?string $target): array
    {
        $worktrees = array_slice($git->worktrees(), 1);

        if ($worktrees === []) {
            throw new UserError('This repository has no worktrees besides the main checkout.');
        }

        if ($target !== null) {
            $absolute = $this->absolute($target);

            foreach ($worktrees as $worktree) {
                if (in_array($target, [$worktree['branch'], basename($worktree['path'])], true) || in_array($worktree['path'], [$absolute, realpath($absolute)], true)) {
                    return $worktree;
                }
            }

            throw new UserError("No worktree matches '{$target}' — git worktree list shows them.");
        }

        if (! Runner::interactive()) {
            throw new UserError(self::USAGE);
        }

        $options = [];

        foreach ($worktrees as $index => $worktree) {
            $options[$index] = basename($worktree['path']).' · '.($worktree['branch'] ?? 'detached').($worktree['prunable'] ? ' · folder missing' : '');
        }

        return $worktrees[(int) select('Remove which worktree?', $options)];
    }

    /**
     * @param  array{database?: array{driver: string, name: string, host: string, port: string, admin?: string}}  $state
     */
    private function databaseToDrop(array $state, EnvFile $env, string $main, string $path, ?bool $drop): ?Database
    {
        $made = $state['database'] ?? null;

        if ($made === null) {
            return null;
        }

        $current = Database::fromEnv($env, $path);
        $mainDatabase = Database::fromEnv(EnvFile::read("{$main}/.env"), $main);

        if ($current === null || $current->driver !== $made['driver'] || ($mainDatabase !== null && $mainDatabase->database === $made['name'])) {
            $this->console->warn("Not dropping {$made['name']}: the .env no longer matches what lwt made.");

            return null;
        }

        $drop ??= Runner::interactive() && confirm("Drop the database {$made['name']}? lwt made it for this worktree.", default: true);

        if (! $drop) {
            $this->console->skip("database — kept {$made['name']}".(Runner::interactive() ? '' : ' (--drop-db drops it)'));

            return null;
        }

        return $current->named($made['name']);
    }

    private function drop(Database $database): void
    {
        try {
            $database->drop();
            $this->console->done("database — dropped {$database->describe()}");
        } catch (PDOException $exception) {
            $this->console->warn("Could not drop {$database->database}: {$exception->getMessage()}");
        }
    }

    private function deleteBranch(Git $git, string $branch, ?string $base, ?bool $delete): void
    {
        $base ??= $git->defaultRemoteBranch() ?? ($git->worktrees()[0]['branch'] ?? 'HEAD');
        $upstream = $git->value(['rev-parse', '--abbrev-ref', '--symbolic-full-name', "{$branch}@{upstream}"]);
        $ahead = $git->commitsAhead($branch, $upstream !== '' ? $upstream : $base);
        $merged = $git->run(['merge-base', '--is-ancestor', $branch, $base])->successful();

        if ($ahead > 0 && ! $merged) {
            $this->console->warn("{$branch} has {$ahead} commit(s) that ".($upstream !== '' ? "{$upstream}" : "{$base}")." doesn't have.");
        }

        $delete ??= Runner::interactive() && confirm("Delete the branch {$branch}?", default: $merged, hint: $merged ? "It's merged into {$base}." : 'Its commits stay reachable only through the reflog.');

        if (! $delete) {
            $this->console->skip("branch — kept {$branch}");

            return;
        }

        // -d checks against the main checkout's HEAD; merged into the base (or nothing of its own) is the check that matters.
        $force = $merged || $ahead === 0 || (Runner::interactive() && confirm("{$branch} isn't merged into {$base}. Delete it anyway?", default: false));

        if ($this->console->run(['git', 'branch', $force ? '-D' : '-d', $branch], $git->cwd)->failed()) {
            $this->console->warn("Kept {$branch} — git branch -D deletes it anyway.");

            return;
        }

        $this->console->done("branch — deleted {$branch}");
    }

    /**
     * `.claude/worktrees/` or a `worktrees/` folder lingers once its last worktree is gone; the folder the main
     * checkout lives in never goes.
     */
    private function removeEmptyParent(string $path, string $main): void
    {
        $parent = dirname($path);

        if ($parent === dirname($main) || $parent === $main || ! is_dir($parent) || (scandir($parent) ?: []) !== ['.', '..']) {
            return;
        }

        rmdir($parent);
        $this->console->done("removed the empty {$parent}");
    }
}
