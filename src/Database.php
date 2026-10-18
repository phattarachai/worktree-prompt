<?php

namespace Phattarachai\WorktreePrompt;

use PDO;
use PDOException;

/**
 * The database a checkout's `.env` points at, and the one a worktree gets for itself:
 * Postgres and MySQL/MariaDB through PDO (create, drop) and their dump tools (copy); SQLite as a file.
 */
final readonly class Database
{
    public const array DRIVERS = ['pgsql', 'mysql', 'mariadb', 'sqlite'];

    private const int MAX_NAME = 63;

    public function __construct(
        public string $driver,
        public string $database,
        public string $host = '',
        public string $port = '',
        public string $username = '',
        public string $password = '',
        public ?string $admin = null,
    ) {}

    /**
     * Null when DB_CONNECTION names a custom connection lwt can't read from the `.env` alone.
     */
    public static function fromEnv(EnvFile $env, string $basePath): ?self
    {
        return self::fromValues($env->all(), $basePath);
    }

    /**
     * @param  array<string, string>  $env
     */
    public static function fromValues(array $env, string $basePath): ?self
    {
        $driver = ($env['DB_CONNECTION'] ?? '') ?: 'sqlite';

        if (! in_array($driver, self::DRIVERS, true)) {
            return null;
        }

        if ($driver === 'sqlite') {
            $file = ($env['DB_DATABASE'] ?? '') ?: 'database/database.sqlite';

            return new self($driver, str_starts_with($file, '/') || $file === ':memory:' ? $file : "{$basePath}/{$file}");
        }

        return new self(
            driver: $driver,
            database: $env['DB_DATABASE'] ?? 'laravel',
            host: $env['DB_HOST'] ?? '127.0.0.1',
            port: $env['DB_PORT'] ?? ($driver === 'pgsql' ? '5432' : '3306'),
            username: $env['DB_USERNAME'] ?? 'root',
            password: $env['DB_PASSWORD'] ?? '',
        );
    }

    /**
     * `<main database>_<slug>`, made safe for every driver and cut to Postgres's 63-byte limit.
     */
    public static function nameFor(string $base, string $slug): string
    {
        $name = trim((string) preg_replace('/_+/', '_', (string) preg_replace('/[^a-z0-9_]/', '_', strtolower("{$base}_{$slug}"))), '_');

        return strlen($name) <= self::MAX_NAME ? $name : rtrim(substr($name, 0, self::MAX_NAME - 9), '_').'_'.substr(md5($name), 0, 8);
    }

    public function named(string $database): self
    {
        return new self($this->driver, $database, $this->host, $this->port, $this->username, $this->password, $this->admin);
    }

    /**
     * Create and drop as another user (`root`, `postgres`) when the app's own user may not: the new
     * database is then granted to (MySQL) or owned by (Postgres) the app's user. Its password, if any,
     * comes from LWT_DB_ADMIN_PASSWORD.
     */
    public function administeredBy(?string $admin): self
    {
        return new self($this->driver, $this->database, $this->host, $this->port, $this->username, $this->password, $admin);
    }

    public function isSqlite(): bool
    {
        return $this->driver === 'sqlite';
    }

    /**
     * This machine (or a `.test` name a local proxy serves) — never a server someone else depends on.
     */
    public function isLocal(): bool
    {
        if ($this->isSqlite()) {
            return true;
        }

        $host = strtolower($this->host);

        return in_array($host, ['', 'localhost', '127.0.0.1', '::1'], true)
            || str_starts_with($host, '/')
            || str_ends_with($host, '.test')
            || str_ends_with($host, '.localhost');
    }

    public function describe(): string
    {
        if ($this->isSqlite()) {
            return "sqlite {$this->database}";
        }

        return "{$this->driver} {$this->database} on ".($this->host === '' ? 'the local socket' : "{$this->host}:{$this->port}");
    }

    public function exists(): bool
    {
        if ($this->isSqlite()) {
            return is_file($this->database);
        }

        $statement = $this->driver === 'pgsql'
            ? $this->pdo()->prepare('SELECT 1 FROM pg_database WHERE datname = ?')
            : $this->pdo()->prepare('SELECT 1 FROM information_schema.schemata WHERE schema_name = ?');
        $statement->execute([$this->database]);

        return $statement->fetchColumn() !== false;
    }

    /**
     * Tables in this database, null when it doesn't exist. Throws when the server can't be reached.
     */
    public function tableCount(): ?int
    {
        if ($this->isSqlite()) {
            return is_file($this->database) ? self::count(new PDO("sqlite:{$this->database}"), "SELECT count(*) FROM sqlite_master WHERE type = 'table'") : null;
        }

        if (! $this->exists()) {
            return null;
        }

        if ($this->driver === 'pgsql') {
            return self::count($this->pdo($this->database), "SELECT count(*) FROM information_schema.tables WHERE table_schema NOT IN ('pg_catalog', 'information_schema')");
        }

        return self::count($this->pdo(), 'SELECT count(*) FROM information_schema.tables WHERE table_schema = ?', [$this->database]);
    }

    /**
     * @param  list<string>  $bindings
     */
    private static function count(PDO $pdo, string $sql, array $bindings = []): int
    {
        $statement = $pdo->prepare($sql);
        $statement->execute($bindings);

        return (int) $statement->fetchColumn();
    }

    public function create(): void
    {
        if ($this->isSqlite()) {
            is_dir(dirname($this->database)) || mkdir(dirname($this->database), 0755, true);
            touch($this->database);

            return;
        }

        $pdo = $this->pdo();

        if ($this->driver === 'pgsql') {
            $pdo->exec('CREATE DATABASE '.$this->identifier().($this->admin === null ? '' : ' OWNER '.$this->identifier($this->username)));

            return;
        }

        $pdo->exec('CREATE DATABASE '.$this->identifier().' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

        if ($this->admin === null) {
            return;
        }

        $hosts = $pdo->prepare('SELECT host FROM mysql.user WHERE user = ?');
        $hosts->execute([$this->username]);

        foreach ($hosts->fetchAll(PDO::FETCH_COLUMN) as $host) {
            $pdo->exec('GRANT ALL PRIVILEGES ON '.$this->identifier().'.* TO '.$pdo->quote($this->username).'@'.$pdo->quote((string) $host));
        }
    }

    /**
     * The server refused because this user may not create or drop databases.
     */
    public static function isPermissionError(PDOException $exception): bool
    {
        return in_array((string) $exception->getCode(), ['42000', '42501', '1044', '1045'], true)
            || str_contains(strtolower($exception->getMessage()), 'denied');
    }

    public function drop(): void
    {
        if ($this->isSqlite()) {
            is_file($this->database) && unlink($this->database);

            return;
        }

        if ($this->driver !== 'pgsql') {
            $pdo = $this->pdo();
            $pdo->exec('DROP DATABASE IF EXISTS '.$this->identifier());

            // DROP DATABASE leaves the grants create() made behind in mysql.db.
            if ($this->admin !== null) {
                $pdo->prepare('DELETE FROM mysql.db WHERE db = ? AND user = ?')->execute([$this->database, $this->username]);
                $pdo->exec('FLUSH PRIVILEGES');
            }

            return;
        }

        try {
            $this->pdo()->exec('DROP DATABASE IF EXISTS '.$this->identifier().' WITH (FORCE)');
        } catch (PDOException) {
            $this->pdo()->exec('DROP DATABASE IF EXISTS '.$this->identifier());
        }
    }

    /**
     * The pipeline that copies this database's schema and rows into `$target`, which must exist and be empty.
     *
     * @return array{script: string, shown: string, env: array<string, string>, tools: list<string>}
     */
    public function copyTo(self $target): array
    {
        if ($this->isSqlite()) {
            $command = 'cp '.escapeshellarg($this->database).' '.escapeshellarg($target->database);

            return ['script' => $command, 'shown' => $command, 'env' => [], 'tools' => ['cp']];
        }

        if ($this->driver === 'pgsql') {
            $connection = array_merge($this->host === '' ? [] : ['--host', $this->host], ['--port', $this->port, '--username', $this->username]);
            $dump = ['pg_dump', '--no-owner', '--no-privileges', ...$connection, $this->database];
            $restore = ['psql', '--quiet', '--set', 'ON_ERROR_STOP=1', ...$connection, '--dbname', $target->database];

            return self::pipeline($dump, $restore, ['PGPASSWORD' => $this->password]);
        }

        $connection = array_merge($this->host === '' ? [] : ['--host', $this->host], ['--port', $this->port, '--user', $this->username]);
        $dump = ['mysqldump', '--single-transaction', '--routines', '--triggers', '--no-tablespaces', ...$connection, $this->database];
        $restore = ['mysql', ...$connection, $target->database];

        return self::pipeline($dump, $restore, ['MYSQL_PWD' => $this->password]);
    }

    /**
     * @param  list<string>  $dump
     * @param  list<string>  $restore
     * @param  array<string, string>  $env
     * @return array{script: string, shown: string, env: array<string, string>, tools: list<string>}
     */
    private static function pipeline(array $dump, array $restore, array $env): array
    {
        $script = implode(' ', array_map('escapeshellarg', $dump)).' | '.implode(' ', array_map('escapeshellarg', $restore));

        return [
            'script' => $script,
            'shown' => Console::describe($dump).' | '.Console::describe($restore),
            'env' => $env,
            'tools' => [$dump[0], $restore[0]],
        ];
    }

    private function identifier(?string $name = null): string
    {
        $name ??= $this->database;

        return $this->driver === 'pgsql'
            ? '"'.str_replace('"', '""', $name).'"'
            : '`'.str_replace('`', '``', $name).'`';
    }

    /**
     * A server connection that isn't to the database being created or dropped: Postgres's `postgres`
     * maintenance database (or `template1`), and no database at all for MySQL.
     */
    private function pdo(?string $database = null): PDO
    {
        $options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5];
        $host = $this->host === '' ? '' : "host={$this->host};port={$this->port};";
        [$username, $password] = $this->admin === null ? [$this->username, $this->password] : [$this->admin, (string) getenv('LWT_DB_ADMIN_PASSWORD')];

        if ($this->driver !== 'pgsql') {
            return new PDO("mysql:{$host}", $username, $password, $options);
        }

        foreach ($database === null ? ['postgres', 'template1'] : [$database] as $maintenance) {
            try {
                return new PDO("pgsql:{$host}dbname={$maintenance}", $username, $password, $options);
            } catch (PDOException $exception) {
                $failure = $exception;
            }
        }

        throw $failure;
    }
}
