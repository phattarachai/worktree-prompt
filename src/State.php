<?php

namespace Phattarachai\WorktreePrompt;

/**
 * What lwt made for a worktree (its database, the base it branched from), kept in the worktree's own git admin
 * folder (`.git/worktrees/<name>/worktree-prompt.json`): outside the working tree, never committed,
 * and gone when git removes the worktree.
 *
 * @phpstan-type Data array{database?: array{driver: string, name: string, host: string, port: string, admin?: string}, base?: string}
 */
final class State
{
    private const string FILE = 'worktree-prompt.json';

    /**
     * @return Data
     */
    public static function read(Git $worktree): array
    {
        $file = self::file($worktree);
        $data = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;

        /** @var Data */
        return is_array($data) ? $data : [];
    }

    /**
     * @param  Data  $changes
     */
    public static function merge(Git $worktree, array $changes): void
    {
        file_put_contents(self::file($worktree), json_encode([...self::read($worktree), ...$changes], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
    }

    private static function file(Git $worktree): string
    {
        return $worktree->gitDir().'/'.self::FILE;
    }
}
