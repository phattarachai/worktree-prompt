<?php

use Phattarachai\WorktreePrompt\Connector;
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

it('keeps the database lwt add made when the worktree is set up again', function (string $db) {
    $project = shop('pgsql');
    $server = FakeServer::start('99.1');
    usePath(postgresTools("{$project->root}/tools", '99.1').":{$this->path}");
    lwt(['add', 'fix/login-redirect', '-n', "--db={$db}"], $project->path);
    $worktree = "{$project->root}/app-login-redirect";
    unlink("{$worktree}/.migrated");

    [$code, $output] = lwt(['setup', '-n', "--db={$db}"], $worktree);

    expect($code)->toBe(0)
        ->and($server->created)->toBe(['shop_login_redirect'])
        ->and($output)->toContain('shop_login_redirect already there, kept')
        ->and($output)->toContain('shop_login_redirect on 127.0.0.1:5432 — its own, migrated')
        ->and($output)->not->toContain('a copy of shop')
        ->and(file_get_contents("{$worktree}/.env"))->toContain("DB_DATABASE=shop_login_redirect\n")
        ->and(file_get_contents("{$worktree}/.migrated"))->toEndWith('DB_DATABASE=shop_login_redirect')
        ->and(lwt(['list'], $project->path)[1])->toContain('db shop_login_redirect');
})->with(['copy', 'empty']);

it('points a replaced .env back at the database lwt add made', function () {
    $project = shop('mysql');
    $server = FakeServer::start('8.4.9');
    lwt(['add', 'fix/login-redirect', '-n', '--db=empty'], $project->path);
    $worktree = "{$project->root}/app-login-redirect";
    copy("{$project->path}/.env", "{$worktree}/.env");

    [$code] = lwt(['setup', '-n', '--db=empty'], $worktree);

    expect($code)->toBe(0)
        ->and($server->created)->toBe(['shop_login_redirect'])
        ->and(file_get_contents("{$worktree}/.env"))->toContain("DB_DATABASE=shop_login_redirect\n");
});

it('keeps the SQLite file lwt add made outside the worktree', function () {
    $project = project();
    $shared = "{$project->root}/databases";
    mkdir($shared);
    touch("{$shared}/shop.sqlite");
    $project->write('.env', "APP_ENV=local\nDB_CONNECTION=sqlite\nDB_DATABASE={$shared}/shop.sqlite\n");
    lwt(['add', 'fix/login-redirect', '-n', '--db=copy'], $project->path);

    [$code] = lwt(['setup', '-n', '--db=copy'], "{$project->root}/app-login-redirect");
    $files = array_map(basename(...), glob("{$shared}/*") ?: []);
    lwt(['remove', 'fix/login-redirect', '-n', '--drop-db'], $project->path);

    expect($code)->toBe(0)
        ->and($files)->toBe(['shop.sqlite', 'shop_login_redirect.sqlite'])
        ->and(array_map(basename(...), glob("{$shared}/*") ?: []))->toBe(['shop.sqlite']);
});

it('creates it again under the same name when it was dropped by hand', function () {
    $project = shop('mysql');
    $server = FakeServer::start('8.4.9');
    lwt(['add', 'fix/login-redirect', '-n', '--db=empty'], $project->path);
    $server->exec('DELETE FROM information_schema.schemata');

    [$code] = lwt(['setup', '-n', '--db=empty'], "{$project->root}/app-login-redirect");

    expect($code)->toBe(0)
        ->and($server->created)->toBe(['shop_login_redirect', 'shop_login_redirect']);
});

it('never takes the main checkout\'s database for the one lwt made', function () {
    $project = shop('mysql');
    $server = FakeServer::start('8.4.9');
    lwt(['add', 'fix/login-redirect', '-n', '--db=empty'], $project->path);
    $worktree = "{$project->root}/app-login-redirect";
    $project->write('.env', str_replace('DB_DATABASE=shop', 'DB_DATABASE=shop_login_redirect', (string) file_get_contents("{$project->path}/.env")));

    [$code, $output] = lwt(['setup', '-n'], $worktree);
    lwt(['setup', '-n', '--db=empty'], $worktree);

    expect($code)->toBe(0)
        ->and($output)->toContain('shop_login_redirect on 127.0.0.1:3306 — shared with the main checkout')
        ->and($server->created)->toBe(['shop_login_redirect', 'shop_login_redirect_app_login_redirect']);
});

it('says whose database the worktree is on when it keeps the one in .env', function (string $name, string $host, string $whose) {
    $project = shop('mysql');
    FakeServer::start('8.4.9');
    lwt(['add', 'feat/x', '-n', '--db=empty'], $project->path);
    $worktree = "{$project->root}/app-x";
    file_put_contents("{$worktree}/.env", "APP_ENV=local\nDB_CONNECTION=mysql\nDB_HOST={$host}\nDB_DATABASE={$name}\nDB_USERNAME=shop\n");

    [$code, $output] = lwt(['setup', '-n'], $worktree);

    expect($code)->toBe(0)
        ->and($output)->toContain("mysql {$name} on {$host}:3306 — {$whose}\n");
})->with([
    'the one lwt made' => ['shop_x', '127.0.0.1', 'its own'],
    'the main checkout\'s' => ['shop', '127.0.0.1', 'shared with the main checkout'],
    'the main checkout\'s, by another name of this machine' => ['shop', 'localhost', 'shared with the main checkout'],
    'one set by hand' => ['other', '127.0.0.1', 'not the main checkout\'s'],
    'the same name on another server' => ['shop', 'db.example.com', 'not the main checkout\'s'],
]);

it('says a SQLite file inside the worktree is its own', function () {
    $project = project();
    $project->write('database/database.sqlite', '');
    $worktree = $project->worktree('feat/x');

    [, $before] = lwt(['setup', '-n'], $worktree);
    lwt(['setup', '-n', '--db=copy'], $worktree);
    [, $after] = lwt(['setup', '-n'], $worktree);

    expect($before)->toContain("{$worktree}/database/database.sqlite — its own, not created yet\n")
        ->and($after)->toContain("{$worktree}/database/database.sqlite — its own\n");
});

it('does not say it kept the shared database when the server of its own can\'t be reached', function () {
    $project = shop('mysql');
    FakeServer::start('8.4.9');
    lwt(['add', 'feat/x', '-n', '--db=empty'], $project->path);
    Connector::$using = fn () => throw new PDOException('Connection refused');

    [$code, $output] = lwt(['setup', '-n', '--db=empty'], "{$project->root}/app-x");

    expect($code)->toBe(1)
        ->and($output)->toContain('Connection refused — kept shop_x')
        ->and($output)->toContain('mysql shop_x on 127.0.0.1:3306 — its own');
});

it('fails when it is asked for a copy of a database it made empty', function () {
    $project = shop('pgsql');
    $server = FakeServer::start('99.1');
    usePath(postgresTools("{$project->root}/tools", '99.1').":{$this->path}");
    lwt(['add', 'feat/x', '-n', '--db=empty'], $project->path);
    $worktree = "{$project->root}/app-x";

    [$code, $output] = lwt(['setup', '-n', '--db=copy'], $worktree);
    [$empty] = lwt(['setup', '-n', '--db=empty'], $worktree);
    $server->exec('DELETE FROM pg_database');
    [$dropped, $copied] = lwt(['setup', '-n', '--db=copy'], $worktree);
    [$again] = lwt(['setup', '-n', '--db=copy'], $worktree);

    expect($code)->toBe(1)
        ->and($output)->toContain('shop_x was made empty, not as a copy of shop — nothing was copied into it')
        ->and($output)->toContain('shop_x on 127.0.0.1:5432 — its own, migrated')
        ->and($empty)->toBe(0)
        ->and($dropped)->toBe(0)
        ->and($copied)->toContain('shop_x on 127.0.0.1:5432 — a copy of shop')
        ->and($again)->toBe(0);
});

it('fails the same way for a SQLite file it made empty inside the worktree', function () {
    $project = project();
    $project->write('database/database.sqlite', 'main data');
    $worktree = $project->worktree('feat/x');
    lwt(['setup', '-n', '--db=empty'], $worktree);

    [$code, $output] = lwt(['setup', '-n', '--db=copy'], $worktree);

    expect($code)->toBe(1)
        ->and($output)->toContain('nothing was copied into it')
        ->and(file_get_contents("{$worktree}/database/database.sqlite"))->not->toBe('main data');
});

it('knows a database a failed copy left empty was then used as an empty one', function () {
    $project = shop('pgsql');
    FakeServer::start('99.1');
    $tools = postgresTools("{$project->root}/tools", '99.1', 'exit 1');
    usePath("{$tools}:{$this->path}");
    lwt(['add', 'feat/x', '-n', '--db=copy'], $project->path);
    $worktree = "{$project->root}/app-x";
    lwt(['setup', '-n', '--db=empty'], $worktree);
    postgresTools($tools, '99.1');

    [$code, $output] = lwt(['setup', '-n', '--db=copy'], $worktree);

    expect($code)->toBe(1)
        ->and($output)->toContain('shop_x was made empty, not as a copy of shop');
});

it('fails when the database it should copy into is there and lwt did not make it', function (string $db, int $expected) {
    $project = shop('mysql');
    $server = FakeServer::start('8.4.9');
    $server->exec('CREATE DATABASE `shop_x`');
    usePath(tools("{$project->root}/tools", ['mysqldump' => 'echo "SELECT 1;"', 'mysql' => 'cat > /dev/null']).":{$this->path}");

    [$code, $output] = lwt(['add', 'feat/x', '-n', "--db={$db}"], $project->path);

    expect($code)->toBe($expected)
        ->and($server->created)->toBe(['shop_x'])
        ->and($output)->toContain('shop_x already exists and lwt didn\'t make it');
})->with([['copy', 1], ['empty', 0]]);
