<?php

namespace Phattarachai\WorktreePrompt;

/**
 * Read-only questions about one checkout. Commands that change the repository go through
 * {@see Console} instead, so the user sees them.
 */
class Git
{
    public function __construct(public readonly Runner $runner, public readonly string $cwd) {}

    /**
     * @param  list<string>  $arguments
     */
    public function run(array $arguments): Result
    {
        return $this->runner->run(['git', ...$arguments], $this->cwd, ['LANGUAGE' => 'en', 'GIT_TERMINAL_PROMPT' => '0']);
    }

    /**
     * @param  list<string>  $arguments
     */
    public function value(array $arguments): string
    {
        $result = $this->run($arguments);

        return $result->successful() ? trim($result->output) : '';
    }

    public function isRepository(): bool
    {
        return $this->value(['rev-parse', '--is-inside-work-tree']) === 'true';
    }

    public function topLevel(): string
    {
        return $this->value(['rev-parse', '--show-toplevel']);
    }

    /**
     * This checkout's own admin folder: `.git` for the main checkout, `.git/worktrees/<name>` for a worktree.
     * Nothing in it is ever committed, and git deletes it with the worktree.
     */
    public function gitDir(): string
    {
        return $this->value(['rev-parse', '--path-format=absolute', '--git-dir']);
    }

    /**
     * @return list<array{path: string, branch: ?string, head: string, bare: bool, locked: bool, prunable: bool}>
     */
    public function worktrees(): array
    {
        $worktrees = [];

        foreach (preg_split('/\n\n+/', $this->value(['worktree', 'list', '--porcelain'])) ?: [] as $block) {
            $fields = [];

            foreach (explode("\n", $block) as $line) {
                [$key, $value] = array_pad(explode(' ', $line, 2), 2, '');
                $fields[$key] = $value;
            }

            if (! isset($fields['worktree'])) {
                continue;
            }

            $worktrees[] = [
                'path' => $fields['worktree'],
                'branch' => isset($fields['branch']) ? (string) preg_replace('#^refs/heads/#', '', $fields['branch']) : null,
                'head' => $fields['HEAD'] ?? '',
                'bare' => array_key_exists('bare', $fields),
                'locked' => array_key_exists('locked', $fields),
                'prunable' => array_key_exists('prunable', $fields),
            ];
        }

        return $worktrees;
    }

    /**
     * The checkout `git clone` made — the first entry of `git worktree list`, whichever worktree we are in.
     */
    public function mainCheckout(): string
    {
        $main = $this->worktrees()[0] ?? null;

        if ($main === null || $main['bare']) {
            throw new UserError('The main repository is bare — run lwt from a checkout that has the project files.');
        }

        return $main['path'];
    }

    /**
     * Where a branch is checked out, if anywhere.
     */
    public function worktreeOf(string $branch): ?string
    {
        foreach ($this->worktrees() as $worktree) {
            if ($worktree['branch'] === $branch) {
                return $worktree['path'];
            }
        }

        return null;
    }

    /**
     * `git status --porcelain`, keyed by path: what is modified, staged, deleted or new (ignored files excluded).
     *
     * @return array<string, string>
     */
    public function status(): array
    {
        $entries = explode("\0", $this->run(['status', '--porcelain=v1', '-z', '--untracked-files=all'])->output);
        $status = [];

        for ($index = 0; $index < count($entries); $index++) {
            $entry = $entries[$index];

            if (strlen($entry) < 4) {
                continue;
            }

            $code = substr($entry, 0, 2);
            $status[substr($entry, 3)] = $code;

            if ($code[0] === 'R' || $code[0] === 'C') {
                $index++;
            }
        }

        return $status;
    }

    public function isTracked(string $path): bool
    {
        return $this->run(['ls-files', '--error-unmatch', '--', $path])->successful();
    }

    /**
     * Ignored, and not tracked — safe for lwt to create or copy without touching the branch.
     */
    public function isIgnored(string $path): bool
    {
        return ! $this->isTracked($path) && $this->run(['check-ignore', '--quiet', '--no-index', '--', $path])->successful();
    }

    public function refExists(string $ref): bool
    {
        return $this->run(['rev-parse', '--verify', '--quiet', "{$ref}^{commit}"])->successful();
    }

    public function localBranchExists(string $branch): bool
    {
        return $this->run(['show-ref', '--verify', '--quiet', "refs/heads/{$branch}"])->successful();
    }

    public function isRemoteRef(string $ref): bool
    {
        return str_starts_with($this->value(['rev-parse', '--symbolic-full-name', $ref]), 'refs/remotes/');
    }

    /**
     * @return list<string>
     */
    public function remotes(): array
    {
        return array_values(array_filter(explode("\n", $this->value(['remote']))));
    }

    /**
     * `origin/main` (what origin/HEAD points at), else the first of origin/main, origin/master; null without a remote.
     */
    public function defaultRemoteBranch(): ?string
    {
        foreach ($this->remotes() as $remote) {
            $head = $this->value(['symbolic-ref', '--quiet', '--short', "refs/remotes/{$remote}/HEAD"]);

            if ($head !== '') {
                return $head;
            }

            foreach (['main', 'master'] as $name) {
                if ($this->refExists("{$remote}/{$name}")) {
                    return "{$remote}/{$name}";
                }
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public function localBranches(): array
    {
        return array_values(array_filter(explode("\n", $this->value(['for-each-ref', '--sort=-committerdate', '--format=%(refname:short)', 'refs/heads']))));
    }

    /**
     * Remote branches as `origin/name`, without the `origin/HEAD` alias.
     *
     * @return list<string>
     */
    public function remoteBranches(): array
    {
        $branches = explode("\n", $this->value(['for-each-ref', '--sort=-committerdate', '--format=%(refname:short)', 'refs/remotes']));

        return array_values(array_filter($branches, fn (string $branch) => $branch !== '' && ! str_ends_with($branch, '/HEAD') && str_contains($branch, '/')));
    }

    /**
     * The remote branch `name` would track, e.g. `origin/fix/login` for `fix/login`.
     */
    public function remoteBranchFor(string $branch): ?string
    {
        foreach ($this->remotes() as $remote) {
            if ($this->refExists("refs/remotes/{$remote}/{$branch}")) {
                return "{$remote}/{$branch}";
            }
        }

        return null;
    }

    /**
     * Files under `database/migrations` that differ between two refs — a hint the branch needs a database of its own.
     */
    public function touchesMigrations(string $base, string $branch): bool
    {
        return $this->value(['diff', '--name-only', "{$base}...{$branch}", '--', 'database/migrations']) !== '';
    }

    /**
     * Commits on `branch` that `base` doesn't have.
     */
    public function commitsAhead(string $branch, string $base): int
    {
        return (int) $this->value(['rev-list', '--count', "{$base}..{$branch}"]);
    }
}
