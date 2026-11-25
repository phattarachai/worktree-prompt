<?php

namespace Phattarachai\WorktreePrompt;

/**
 * The folder whose `pg_dump` and `psql` can copy a Postgres server's databases. pg_dump refuses a server of a newer
 * major version, and a machine often has several: the first on the PATH may be older than the server on this port.
 */
final class PostgresTools
{
    /** @var list<string> where versioned installs live, off the PATH: Homebrew on Apple silicon and Intel, Debian and Ubuntu */
    private const array VERSIONED = ['/opt/homebrew/opt/postgresql@*/bin', '/usr/local/opt/postgresql@*/bin', '/usr/lib/postgresql/*/bin'];

    /**
     * The server's own major version when there is one (a newer pg_dump can write settings an older server refuses),
     * else the closest newer; the PATH before the versioned installs.
     *
     * @param  ?list<string>  $folders  globs to look in, in order
     */
    public static function folderFor(string $serverVersion, Runner $runner, string $cwd, ?array $folders = null): string
    {
        $major = self::major($serverVersion);
        $versions = [];

        foreach (array_unique(array_merge(...array_map(fn (string $folder) => glob($folder) ?: [], $folders ?? self::folders()))) as $folder) {
            if (is_executable("{$folder}/pg_dump") && is_executable("{$folder}/psql")) {
                $output = $runner->run(["{$folder}/pg_dump", '--version'], $cwd)->output;
                preg_match('/\d+(\.\d+)*/', $output, $match) === 1 && $versions[$folder] = $match[0];
            }
        }

        $fitting = array_filter(array_map(self::major(...), $versions), fn (int $version) => $version >= $major);
        asort($fitting);

        return array_key_first($fitting) ?? throw new UserError(
            'the Postgres server is version '.explode(' ', $serverVersion)[0]." and copying from it needs pg_dump and psql {$major} or newer — ".($versions === []
                ? 'found none.'
                : 'found only pg_dump '.implode(', ', array_map(fn (string $folder, string $version) => "{$version} ({$folder})", array_keys($versions), $versions)).'.')
        );
    }

    /**
     * @return list<string>
     */
    private static function folders(): array
    {
        return [...array_values(array_filter(explode(PATH_SEPARATOR, (string) getenv('PATH')))), ...self::VERSIONED];
    }

    private static function major(string $version): int
    {
        return (int) $version;
    }
}
