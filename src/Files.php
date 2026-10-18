<?php

namespace Phattarachai\WorktreePrompt;

/**
 * Copies that cost almost nothing: on APFS (macOS) and btrfs/XFS (Linux) a copy-on-write clone of vendor
 * or node_modules takes seconds and no extra disk, and unlike a symlink it is the worktree's own.
 */
final class Files
{
    /**
     * @return list<string>
     */
    public static function cloneCommand(string $from, string $to): array
    {
        return PHP_OS_FAMILY === 'Darwin'
            ? ['cp', '-cR', $from, $to]
            : ['cp', '-R', '--reflink=auto', $from, $to];
    }

    /**
     * Copy a file or folder from the main checkout; a clone where the filesystem can, a plain copy where it can't.
     */
    public static function copy(Console $console, string $from, string $to, string $cwd): bool
    {
        is_dir(dirname($to)) || mkdir(dirname($to), 0755, true);

        if ($console->run(self::cloneCommand($from, $to), $cwd, 'Copying '.basename($from))->successful()) {
            return true;
        }

        self::remove($to);

        return $console->run(['cp', '-R', $from, $to], $cwd, 'Copying '.basename($from))->successful();
    }

    /**
     * Delete a path lwt made; a symlink goes, never what it points at.
     */
    public static function remove(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            unlink($path);

            return;
        }

        if (is_dir($path)) {
            exec('rm -rf '.escapeshellarg($path));
        }
    }

    public static function sameContents(string $first, string $second): bool
    {
        return is_file($first) && is_file($second) && hash_file('sha256', $first) === hash_file('sha256', $second);
    }
}
