<?php

namespace Phattarachai\WorktreePrompt\Tests;

use PDO;
use Phattarachai\WorktreePrompt\Connector;

/**
 * Stands in for a Postgres or MySQL server: the catalogs lwt reads are SQLite tables, and CREATE DATABASE is recorded.
 */
final class FakeServer extends PDO
{
    /** @var list<string> */
    public array $created = [];

    public function __construct(private readonly string $version)
    {
        parent::__construct('sqlite::memory:');
        $this->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        parent::exec('CREATE TABLE pg_database (datname text)');
        parent::exec("ATTACH ':memory:' AS information_schema");
        parent::exec('CREATE TABLE information_schema.schemata (schema_name text)');
        parent::exec('CREATE TABLE information_schema.tables (table_schema text, table_name text)');
    }

    /**
     * Every connection lwt opens, until the test is over.
     */
    public static function start(string $version): self
    {
        $server = new self($version);
        Connector::$using = fn () => $server;

        return $server;
    }

    public function addTable(string $schema, string $table): void
    {
        $this->prepare('INSERT INTO information_schema.tables VALUES (?, ?)')->execute([$schema, $table]);
    }

    public function exec(string $statement): int|false
    {
        if (preg_match('/^CREATE DATABASE ["`](.+?)["`]/', $statement, $match) !== 1) {
            return parent::exec($statement);
        }

        $this->created[] = $match[1];
        $this->prepare('INSERT INTO pg_database VALUES (?)')->execute([$match[1]]);
        $this->prepare('INSERT INTO information_schema.schemata VALUES (?)')->execute([$match[1]]);

        return 0;
    }

    public function getAttribute(int $attribute): mixed
    {
        return $attribute === PDO::ATTR_SERVER_VERSION ? $this->version : parent::getAttribute($attribute);
    }
}
