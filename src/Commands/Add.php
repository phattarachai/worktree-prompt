<?php

namespace Phattarachai\WorktreePrompt\Commands;

use Phattarachai\WorktreePrompt\Console;
use Phattarachai\WorktreePrompt\Git;
use Phattarachai\WorktreePrompt\Runner;
use Phattarachai\WorktreePrompt\State;
use Phattarachai\WorktreePrompt\UserError;
use Phattarachai\WorktreePrompt\WorktreeSetup;

use function Laravel\Prompts\suggest;
use function Laravel\Prompts\text;

/**
 * `lwt add [branch]`: fetch, pick or create the branch, pick the folder, `git worktree add`, then set it up.
 */
class Add extends Command
{
    private const string USAGE = 'Without a terminal: lwt add <branch> [--base=origin/main] [--path=../app-name] [--db=shared|empty|copy] [--url=] [--npm] [--build] -n';

    public function __invoke(array $args): int
    {
        $main = $this->mainCheckout();
        $mainGit = new Git($this->runner, $main);
        $branch = self::argument($args);
        $base = self::option($args, 'base');
        $path = self::option($args, 'path');

        if (! self::flag($args, '--no-fetch') && $mainGit->remotes() !== []) {
            if ($this->console->run(['git', 'fetch', '--all', '--prune', '--quiet'], $main, 'Fetching')->failed()) {
                $this->console->warn('git fetch failed — branching from what this machine already has.');
            }
        }

        $branch ??= $this->askBranch($mainGit);
        $remote = $mainGit->localBranchExists($branch) ? null : $mainGit->remoteBranchFor($branch);
        $isNew = ! $mainGit->localBranchExists($branch) && $remote === null;

        if (($elsewhere = $mainGit->worktreeOf($branch)) !== null) {
            throw new UserError("'{$branch}' is already checked out at {$elsewhere} — work there (lwt setup sets that folder up).");
        }

        $base = $isNew ? ($base ?? $this->askBase($mainGit)) : null;

        if ($base !== null && ! $mainGit->refExists($base)) {
            throw new UserError("Base '{$base}' doesn't exist.");
        }

        $path = $this->absolute($path ?? $this->askPath($main, $branch));

        if (file_exists($path) && (! is_dir($path) || (scandir($path) ?: []) !== ['.', '..'])) {
            throw new UserError("{$path} already exists and isn't empty.");
        }

        $compareWith = $mainGit->defaultRemoteBranch();
        $suggested = ! $isNew && $compareWith !== null && $mainGit->touchesMigrations($compareWith, $remote ?? $branch) ? 'copy' : 'shared';
        $options = $this->setupOptions($args, $main, $path, $suggested);
        $options->slug = self::slug($branch);

        $command = match (true) {
            $isNew => ['git', 'worktree', 'add', ...($mainGit->isRemoteRef((string) $base) ? ['--no-track'] : []), '-b', $branch, $path, (string) $base],
            $remote !== null => ['git', 'worktree', 'add', '--track', '-b', $branch, $path, $remote],
            default => ['git', 'worktree', 'add', $path, $branch],
        };

        if ($this->console->run($command, $main)->failed()) {
            return 1;
        }

        $this->console->done('worktree — '.match (true) {
            $isNew => "new branch {$branch} from {$base}",
            $remote !== null => "{$branch}, tracking {$remote}",
            default => $branch,
        });

        if ($base !== null) {
            State::merge(new Git($this->runner, $path), ['base' => $base]);
        }

        $ready = (new WorktreeSetup($this->console, $main, $path))($options);

        $this->console->line();
        $this->console->line('  '.Console::cd($path));

        return $ready ? 0 : 1;
    }

    private function askBranch(Git $git): string
    {
        if (! Runner::interactive()) {
            throw new UserError(self::USAGE);
        }

        $taken = array_filter(array_map(fn (array $worktree) => $worktree['branch'], $git->worktrees()));
        $local = array_values(array_diff($git->localBranches(), $taken));
        $remote = array_values(array_diff(array_map(fn (string $ref) => (string) preg_replace('#^[^/]+/#', '', $ref), $git->remoteBranches()), $git->localBranches(), $taken));
        $branches = array_values(array_unique([...$local, ...$remote]));

        return trim(suggest(
            label: 'Branch',
            options: fn (string $value) => array_values(array_filter($branches, fn (string $branch) => $value === '' || str_contains(strtolower($branch), strtolower($value)))),
            placeholder: 'fix/login-redirect',
            required: true,
            validate: fn (string $value) => $git->run(['check-ref-format', '--branch', trim($value)])->failed() ? 'Not a valid branch name.' : null,
            hint: 'An existing branch (local or remote), or a new name.',
        ));
    }

    private function askBase(Git $git): string
    {
        $default = $git->defaultRemoteBranch() ?? ($git->worktrees()[0]['branch'] ?? 'HEAD');

        if (! Runner::interactive()) {
            return $default;
        }

        $refs = array_values(array_unique([$default, ...$git->remoteBranches(), ...$git->localBranches()]));

        return trim(suggest(
            label: 'Branch from',
            options: fn (string $value) => array_values(array_filter($refs, fn (string $ref) => $value === '' || str_contains(strtolower($ref), strtolower($value)))),
            default: $default,
            required: true,
            validate: fn (string $value) => $git->refExists(trim($value)) ? null : 'No such branch or commit.',
            hint: 'A remote branch is used with --no-track, so the first push sets the upstream to the new name.',
        ));
    }

    private function askPath(string $main, string $branch): string
    {
        $default = dirname($main).'/'.basename($main).'-'.self::slug($branch);

        if (! Runner::interactive()) {
            return $default;
        }

        return trim(text(
            label: 'Folder',
            default: $default,
            required: true,
            validate: fn (string $value) => file_exists($this->absolute(trim($value))) && (scandir($this->absolute(trim($value))) ?: []) !== ['.', '..'] ? 'That folder already exists.' : null,
            hint: 'Beside the main checkout, so a parked folder (~/www → <folder>.test) serves it as its own site.',
        ));
    }
}
