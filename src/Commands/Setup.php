<?php

namespace Phattarachai\WorktreePrompt\Commands;

use Phattarachai\WorktreePrompt\Git;
use Phattarachai\WorktreePrompt\UserError;
use Phattarachai\WorktreePrompt\WorktreeSetup;

/**
 * `lwt setup [path]`: set up a worktree that already exists — one `git worktree add`, git-prompt or the
 * Claude app made. Safe to run again: whatever is already in place is kept.
 */
class Setup extends Command
{
    public function __invoke(array $args): int
    {
        $main = $this->mainCheckout();
        $argument = self::argument($args);
        $path = $argument === null ? $this->git->topLevel() : (string) realpath($this->absolute($argument));
        $known = array_map(fn (array $worktree) => (string) realpath($worktree['path']), $this->git->worktrees());

        if ($path === '' || ! in_array($path, $known, true)) {
            throw new UserError(($argument ?? $this->cwd).' is not a worktree of this repository — git worktree list shows them.');
        }

        $mainGit = new Git($this->runner, $main);
        $base = $mainGit->defaultRemoteBranch();
        $branch = (new Git($this->runner, $path))->value(['symbolic-ref', '--quiet', '--short', 'HEAD']);
        $touchesMigrations = $base !== null && $branch !== '' && $mainGit->touchesMigrations($base, $branch);
        $options = $this->setupOptions($args, $main, $path, $touchesMigrations ? 'copy' : 'shared');

        if ($touchesMigrations && $options->database === 'shared') {
            $this->console->warn("{$branch} changes database/migrations — consider --db=copy so they don't run on the shared database. Tests stay on the shared test database: apply only a new migration there, php artisan migrate --path=<file> --env=testing.");
        }

        return (new WorktreeSetup($this->console, $main, $path))($options) ? 0 : 1;
    }
}
