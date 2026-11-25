<?php

use Phattarachai\WorktreePrompt\PostgresTools;
use Phattarachai\WorktreePrompt\UserError;

beforeEach(function () {
    $this->root = project()->root;
    $this->find = fn (string $server, string ...$folders) => PostgresTools::folderFor($server, $this->runner, $this->root, array_map(fn (string $folder) => "{$this->root}/{$folder}", $folders));
});

it('skips a pg_dump older than the server, on the PATH first, for a versioned install', function () {
    postgresTools("{$this->root}/bin", '17.9');
    $fits = postgresTools("{$this->root}/opt/postgresql@18/bin", '18.6');
    postgresTools("{$this->root}/opt/postgresql@19/bin", '19.1');

    expect(($this->find)('18.6 (Homebrew)', 'bin', 'opt/postgresql@*/bin'))->toBe($fits);
});

it('takes the first on the PATH when several match the server', function () {
    $first = postgresTools("{$this->root}/bin", '18.1');
    postgresTools("{$this->root}/opt/postgresql@18/bin", '18.6');

    expect(($this->find)('18.6', 'bin', 'opt/postgresql@*/bin'))->toBe($first);
});

it('takes the closest newer one when none is the server\'s own version', function () {
    postgresTools("{$this->root}/bin", '20beta1');
    $closest = postgresTools("{$this->root}/opt/postgresql@18/bin", '18.6');

    expect(($this->find)('17.9 (Debian 17.9-1.pgdg12+1)', 'bin', 'opt/postgresql@*/bin'))->toBe($closest);
});

it('needs psql from the same folder', function () {
    postgresTools("{$this->root}/bin", '18.6');
    unlink("{$this->root}/bin/psql");
    $both = postgresTools("{$this->root}/opt/postgresql@18/bin", '18.6');

    expect(($this->find)('18.6', 'bin', 'opt/postgresql@*/bin'))->toBe($both);
});

it('names the server version and what it found when nothing fits', function () {
    $old = postgresTools("{$this->root}/bin", '17.9');

    expect(fn () => ($this->find)('18.6 (Homebrew)', 'bin'))->toThrow(UserError::class, "the Postgres server is version 18.6 and copying from it needs pg_dump and psql 18 or newer — found only pg_dump 17.9 ({$old}).")
        ->and(fn () => ($this->find)('18.6', 'nowhere'))->toThrow(UserError::class, 'found none.');
});
