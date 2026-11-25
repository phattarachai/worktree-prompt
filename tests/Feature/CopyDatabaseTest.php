<?php

use Phattarachai\WorktreePrompt\Tests\FakeServer;
use Phattarachai\WorktreePrompt\Tests\TestProject;

/**
 * A project on a server database, whose worktree database is `shop_x`.
 */
function shop(string $driver): TestProject
{
    $project = project();
    $project->write('.env', "APP_ENV=local\nDB_CONNECTION={$driver}\nDB_HOST=127.0.0.1\nDB_DATABASE=shop\nDB_USERNAME=shop\n");

    return $project;
}

it('copies with the pg_dump and psql that fit the server, not the first on the PATH', function () {
    $project = shop('pgsql');
    $server = FakeServer::start('99.1 (Homebrew)');
    $old = postgresTools("{$project->root}/old", '17.9', 'echo "aborting because of server version mismatch" >&2; exit 1');
    $fits = postgresTools("{$project->root}/new", '99.2');
    usePath("{$old}:{$fits}:{$this->path}");

    [$code, $output] = lwt(['add', 'feat/x', '-n', '--db=copy'], $project->path);

    expect($code)->toBe(0)
        ->and($server->created)->toBe(['shop_x'])
        ->and($output)->toContain("→ {$fits}/pg_dump --no-owner")
        ->and($output)->toContain("| {$fits}/psql --quiet")
        ->and($output)->toContain('shop_x on 127.0.0.1:5432 — a copy of shop')
        ->and(file_get_contents("{$project->root}/app-x/.migrated"))->toEndWith('DB_DATABASE=shop_x');
});

it('says which pg_dump is too old before it creates the database', function () {
    $project = shop('pgsql');
    $server = FakeServer::start('99.1 (Homebrew)');
    $old = postgresTools("{$project->root}/old", '17.9');
    usePath("{$old}:{$this->path}");

    [$code, $output] = lwt(['add', 'feat/x', '-n', '--db=copy'], $project->path);

    expect($code)->toBe(1)
        ->and($server->created)->toBe([])
        ->and($output)->toContain('the Postgres server is version 99.1')
        ->and($output)->toContain("17.9 ({$old})")
        ->and($output)->toContain('shop on 127.0.0.1:5432 — shared with the main checkout')
        ->and(file_get_contents("{$project->root}/app-x/.env"))->toContain("DB_DATABASE=shop\n")
        ->and(file_exists("{$project->root}/app-x/.migrated"))->toBeFalse();
});

it('fails, and neither migrates nor calls it a copy, when the copy fails', function (string $driver, string $port) {
    $project = shop($driver);
    $server = FakeServer::start('99.1');
    $tools = postgresTools("{$project->root}/tools", '99.1', 'echo boom >&2; exit 1');
    tools($tools, ['mysqldump' => 'echo boom >&2; exit 2', 'mysql' => 'cat > /dev/null']);
    usePath("{$tools}:{$this->path}");

    [$code, $output] = lwt(['add', 'feat/x', '-n', '--db=copy'], $project->path);

    expect($code)->toBe(1)
        ->and($server->created)->toBe(['shop_x'])
        ->and($output)->toContain('boom')
        ->and($output)->toContain("{$driver} shop_x on 127.0.0.1:{$port} — EMPTY: the copy of shop failed")
        ->and($output)->not->toContain('a copy of shop')
        ->and($output)->not->toContain('migrations — ran')
        ->and(file_exists("{$project->root}/app-x/.migrated"))->toBeFalse();
})->with([['pgsql', '5432'], ['mysql', '3306'], ['mariadb', '3306']]);

it('fails the same way for a SQLite file', function () {
    $project = project();
    $worktree = $project->worktree('feat/x');

    [$code, $output] = lwt(['setup', '-n', '--db=copy'], $worktree);

    expect($code)->toBe(1)
        ->and($output)->toContain('EMPTY: the copy of')
        ->and($output)->not->toContain('a copy of')
        ->and(file_exists("{$worktree}/.migrated"))->toBeFalse();
});

it('creates no MySQL database when mysqldump is missing', function () {
    $project = shop('mysql');
    $server = FakeServer::start('8.4.9');
    $this->runner->available = ['mysqldump' => false, 'mysql' => true];

    [$code, $output] = lwt(['add', 'feat/x', '-n', '--db=copy'], $project->path);

    expect($code)->toBe(1)
        ->and($server->created)->toBe([])
        ->and($output)->toContain('it needs mysqldump on your PATH')
        ->and(file_exists("{$project->root}/app-x/.migrated"))->toBeFalse();
});

it('copies again into the same, still empty database when run again', function () {
    $project = shop('pgsql');
    $server = FakeServer::start('99.1');
    $tools = postgresTools("{$project->root}/tools", '99.1', 'exit 1');
    usePath("{$tools}:{$this->path}");
    lwt(['add', 'feat/x', '-n', '--db=copy'], $project->path);
    $worktree = "{$project->root}/app-x";

    [$shared, $sharedOutput] = lwt(['setup', '-n'], $worktree);
    [$failed] = lwt(['setup', '-n', '--db=copy'], $worktree);
    $migrated = file_exists("{$worktree}/.migrated");
    postgresTools($tools, '99.1');
    [$code, $output] = lwt(['setup', '-n', '--db=copy'], $worktree);

    expect($shared)->toBe(0)
        ->and($sharedOutput)->toContain('shop_x on 127.0.0.1:5432 — EMPTY: the copy of shop failed')
        ->and($failed)->toBe(1)
        ->and($migrated)->toBeFalse()
        ->and($code)->toBe(0)
        ->and($server->created)->toBe(['shop_x'])
        ->and($output)->toContain('shop_x on 127.0.0.1:5432 — a copy of shop')
        ->and(file_exists("{$worktree}/.migrated"))->toBeTrue();
});

it('never copies or migrates over what a failed copy left', function (string $db) {
    $project = shop('mysql');
    $server = FakeServer::start('8.4.9');
    $tools = tools("{$project->root}/tools", ['mysqldump' => 'exit 2', 'mysql' => 'cat > /dev/null']);
    usePath("{$tools}:{$this->path}");
    lwt(['add', 'feat/x', '-n', '--db=copy'], $project->path);
    $server->addTable('shop_x', 'orders');
    tools($tools, ['mysqldump' => 'echo "SELECT 1;"']);

    [$code, $output] = lwt(['setup', '-n', "--db={$db}"], "{$project->root}/app-x");

    expect($code)->toBe(1)
        ->and($output)->toContain('not copying or migrating over what is there')
        ->and($output)->toContain('INCOMPLETE (1 tables): the copy of shop failed')
        ->and(file_exists("{$project->root}/app-x/.migrated"))->toBeFalse();
})->with(['copy', 'empty']);
