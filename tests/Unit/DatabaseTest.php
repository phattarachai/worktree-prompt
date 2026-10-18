<?php

use Phattarachai\WorktreePrompt\Database;

it('names a worktree database after the main one and the branch', function () {
    expect(Database::nameFor('shop_db', 'login-redirect'))->toBe('shop_db_login_redirect')
        ->and(Database::nameFor('Shop-DB', 'feat.v2'))->toBe('shop_db_feat_v2');
});

it('keeps long names within the 63 bytes Postgres allows, and distinct', function () {
    $first = Database::nameFor(str_repeat('a', 50), 'a-very-long-branch-name-one');
    $second = Database::nameFor(str_repeat('a', 50), 'a-very-long-branch-name-two');

    expect(strlen($first))->toBeLessThanOrEqual(63)
        ->and($first)->not->toBe($second);
});

it('treats only this machine as local', function (string $host, bool $local) {
    expect((new Database('pgsql', 'shop', $host, '5432'))->isLocal())->toBe($local);
})->with([
    ['127.0.0.1', true],
    ['localhost', true],
    ['', true],
    ['/var/run/postgresql', true],
    ['db.test', true],
    ['10.0.0.5', false],
    ['prod-db.example.com', false],
    ['mysql', false],
]);

it('copies Postgres with pg_dump into psql, stopping at the first error', function () {
    $copy = (new Database('pgsql', 'shop', '127.0.0.1', '5432', 'shop', 'secret'))->copyTo(new Database('pgsql', 'shop_login', '127.0.0.1', '5432', 'shop', 'secret'));

    expect($copy['shown'])->toBe('pg_dump --no-owner --no-privileges --host 127.0.0.1 --port 5432 --username shop shop | psql --quiet --set ON_ERROR_STOP=1 --host 127.0.0.1 --port 5432 --username shop --dbname shop_login')
        ->and($copy['env'])->toBe(['PGPASSWORD' => 'secret'])
        ->and($copy['shown'])->not->toContain('secret');
});
