<?php

namespace Phattarachai\WorktreePrompt;

/**
 * What `lwt setup` (and the setup half of `lwt add`) does to a worktree. The defaults are the safe,
 * cheap ones an agent gets with `-n`: real vendor, a copied `.env`, the shared database, no Node.
 */
final class SetupOptions
{
    /** @var list<string> auto: copy when composer.lock matches the main checkout's, else composer install */
    public const array VENDOR = ['auto', 'copy', 'install', 'skip'];

    /** @var list<string> */
    public const array ENV = ['copy', 'symlink', 'skip'];

    /** @var list<string> shared: the main checkout's; empty: a new one, migrated; copy: a new one with the main one's data */
    public const array DATABASE = ['shared', 'empty', 'copy'];

    /** @var list<string> auto: copy when the lockfile matches, else a clean install */
    public const array NODE = ['skip', 'auto', 'copy', 'install'];

    public function __construct(
        public string $vendor = 'auto',
        public string $env = 'copy',
        public ?string $url = null,
        public string $database = 'shared',
        public bool $seed = false,
        public string $node = 'skip',
        public bool $build = false,
        public ?string $slug = null,
        public ?string $databaseAdmin = null,
    ) {}

    public function validate(): self
    {
        foreach (['vendor' => self::VENDOR, 'env' => self::ENV, 'database' => self::DATABASE, 'node' => self::NODE] as $option => $allowed) {
            if (! in_array($this->{$option}, $allowed, true)) {
                throw new UserError("--{$option} must be one of: ".implode(', ', $allowed).'.');
            }
        }

        if ($this->env !== 'copy' && ($this->database !== 'shared' || $this->url !== null)) {
            throw new UserError('A database of its own or a new APP_URL needs --env=copy: a symlinked .env is the main checkout\'s file.');
        }

        if ($this->build && $this->node === 'skip') {
            $this->node = 'auto';
        }

        return $this;
    }
}
