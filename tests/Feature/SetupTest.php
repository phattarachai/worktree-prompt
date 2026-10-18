<?php

use Phattarachai\WorktreePrompt\Result;
use Phattarachai\WorktreePrompt\Tests\FakeRunner;
use Phattarachai\WorktreePrompt\Tests\TestProject;

it('copies vendor for real when composer.lock matches, so App\ loads from the worktree', function () {
    $project = project();
    $worktree = $project->worktree('feat/x');

    [$code, $output] = lwt(['setup', '-n'], $worktree);

    expect($code)->toBe(0)
        ->and(is_link("{$worktree}/vendor"))->toBeFalse()
        ->and(is_file("{$worktree}/vendor/autoload.php"))->toBeTrue()
        ->and($output)->toContain('cp -')
        ->and($output)->toContain('App\ loads from this worktree')
        ->and($this->runner->ran('composer', 'install'))->toBeFalse()
        ->and(TestProject::exec($worktree, ['git', 'status', '--porcelain']))->toBe('');
});

it('replaces a symlinked vendor, which would run the main checkout\'s code', function () {
    $project = project();
    $worktree = $project->worktree('feat/x');
    symlink("{$project->path}/vendor", "{$worktree}/vendor");

    [$code, $output] = lwt(['setup', '-n'], $worktree);

    expect($code)->toBe(0)
        ->and(is_link("{$worktree}/vendor"))->toBeFalse()
        ->and($output)->toContain('vendor was a symlink');
});

it('runs composer install when the branch changed composer.lock', function () {
    $project = project();
    $worktree = $project->worktree('feat/deps');
    $lock = json_decode((string) file_get_contents("{$worktree}/composer.lock"), true);
    $lock['content-hash'] = str_repeat('0', 32);
    file_put_contents("{$worktree}/composer.lock", json_encode($lock, JSON_PRETTY_PRINT)."\n");
    TestProject::exec($worktree, ['git', '-c', 'user.name=T', '-c', 'user.email=t@example.com', 'commit', '--quiet', '-am', 'Change deps']);

    [$code] = lwt(['setup', '-n'], $worktree);

    expect($code)->toBe(0)
        ->and($this->runner->ran('composer', 'install'))->toBeTrue()
        ->and(is_file("{$worktree}/vendor/autoload.php"))->toBeTrue();
});

it('copies .env and the gitignored .env.* files, and never writes over a tracked one', function () {
    $project = project();
    $project->write('.env.local', "LOCAL=1\n");
    $project->write('.env.testing', "DB_DATABASE=changed_in_main\n");
    $worktree = $project->worktree('feat/x');

    [$code, $output] = lwt(['setup', '-n'], $worktree);

    expect($code)->toBe(0)
        ->and(is_link("{$worktree}/.env"))->toBeFalse()
        ->and(file_get_contents("{$worktree}/.env"))->toContain('APP_URL=http://fixture.test')
        ->and(file_get_contents("{$worktree}/.env.local"))->toBe("LOCAL=1\n")
        ->and(file_get_contents("{$worktree}/.env.testing"))->toBe("DB_CONNECTION=pgsql\nDB_DATABASE=fixture_testing\n")
        ->and($output)->toContain('.env.local — copied');
});

it('checks the tests\' shared database, and says how to load it when it is missing', function () {
    $project = project();
    $testing = "{$project->root}/testing.sqlite";
    $project->write('.env.testing', "DB_CONNECTION=sqlite\nDB_DATABASE={$testing}\n");
    $project->write('storage/app/snapshots/testing.sql', '-- baseline');
    $project->git('add', '--all');
    $project->git('commit', '--quiet', '-m', 'Baseline');
    $project->git('push', '--quiet');
    $missing = $project->worktree('feat/missing');

    [, $before] = lwt(['setup', '-n'], $missing);
    (new PDO("sqlite:{$testing}"))->exec('CREATE TABLE posts (id integer)');
    [, $after] = lwt(['setup', '-n', $project->worktree('feat/loaded')], $project->path);

    expect($before)->toContain("doesn't exist — load it once, while no other checkout is testing: php artisan snapshot:load testing --env=testing --force")
        ->and($after)->toContain('(1 tables) — shared by every checkout');
});

it('symlinks .env when asked, and refuses a symlink with a database of its own', function () {
    $project = project();
    $worktree = $project->worktree('feat/x');

    [$refused, $output] = lwt(['setup', '-n', '--env=symlink', '--db=copy'], $worktree);
    [$code] = lwt(['setup', '-n', '--env=symlink'], $worktree);

    expect($refused)->toBe(1)
        ->and($output)->toContain('needs --env=copy')
        ->and($code)->toBe(0)
        ->and(readlink("{$worktree}/.env"))->toBe("{$project->path}/.env");
});

it('creates the storage folders git doesn\'t keep', function () {
    $project = project();
    $worktree = $project->worktree('feat/x');

    lwt(['setup', '-n'], $worktree);

    foreach (['storage/framework/views', 'storage/framework/cache/data', 'storage/framework/sessions', 'storage/logs', 'bootstrap/cache'] as $dir) {
        expect(is_dir("{$worktree}/{$dir}"))->toBeTrue();
    }
});

it('copies public/build for the Vite manifest, but never public/hot', function () {
    $project = project();
    $project->write('public/build/manifest.json', '{}');
    $project->write('public/hot', 'http://localhost:5173');
    $worktree = $project->worktree('feat/x');

    lwt(['setup', '-n'], $worktree);

    expect(file_get_contents("{$worktree}/public/build/manifest.json"))->toBe('{}')
        ->and(file_exists("{$worktree}/public/hot"))->toBeFalse();
});

it('puts back tracked files the setup changed, and leaves files changed before it alone', function () {
    $project = project();
    $project->git('rm', '--quiet', 'composer.lock');
    $project->git('commit', '--quiet', '-m', 'No lock');
    $project->git('push', '--quiet');
    $worktree = $project->worktree('feat/x');
    file_put_contents("{$worktree}/package.json", "{\"mine\": true}\n");

    $runner = new FakeRunner(fakes: ['composer' => function (array $command, string $cwd) use (&$runner): Result {
        file_put_contents("{$cwd}/README.md", "republished\n");
        file_put_contents("{$cwd}/package.json", "{\"composer\": true}\n");

        return $runner->real($command, $cwd);
    }]);

    [$code, $output] = lwt(['setup', '-n'], $worktree, $runner);

    expect($code)->toBe(0)
        ->and(file_get_contents("{$worktree}/README.md"))->toBe("fixture\n")
        ->and(file_get_contents("{$worktree}/package.json"))->toBe("{\"composer\": true}\n")
        ->and($output)->toContain('restored 1 tracked file(s) the setup changed: README.md')
        ->and($output)->toContain('composer.lock');
});

it('gives the worktree its own copy of a SQLite database, then migrates it', function () {
    $project = project();
    $project->write('database/database.sqlite', 'main data');
    $worktree = $project->worktree('feat/x');

    [$code, $output] = lwt(['setup', '-n', '--db=copy'], $worktree);

    expect($code)->toBe(0)
        ->and(file_get_contents("{$worktree}/database/database.sqlite"))->toBe('main data')
        ->and(file_get_contents("{$worktree}/.migrated"))->toStartWith('migrate --force')
        ->and(file_get_contents("{$project->path}/.env"))->not->toContain('DB_DATABASE')
        ->and($output)->toContain('a copy of');
});

it('fails, and keeps the shared database, when a database of its own can\'t be made', function () {
    $project = project();
    file_put_contents("{$project->path}/.env", "DB_CONNECTION=pgsql\nDB_HOST=10.0.0.5\nDB_DATABASE=shop\n");
    $worktree = $project->worktree('feat/x');

    [$code, $output] = lwt(['setup', '-n', '--db=copy'], $worktree);

    expect($code)->toBe(1)
        ->and($output)->toContain('DB_HOST=10.0.0.5 is not this machine')
        ->and(file_get_contents("{$worktree}/.env"))->toContain('DB_DATABASE=shop')
        ->and(file_exists("{$worktree}/.migrated"))->toBeFalse();
});

it('moves APP_URL, and the keys on the same host with it', function () {
    $project = project();
    $worktree = $project->worktree('feat/x');

    lwt(['setup', '-n', '--url=http://app-x.test'], $worktree);

    expect(file_get_contents("{$worktree}/.env"))->toContain("APP_URL=http://app-x.test\n")
        ->and(file_get_contents("{$worktree}/.env"))->toContain("SESSION_DOMAIN=app-x.test\n")
        ->and(file_get_contents("{$project->path}/.env"))->toContain('APP_URL=http://fixture.test');
});

it('copies node_modules when the lockfile matches, and runs a clean install when it doesn\'t', function () {
    $project = project();
    $project->write('node_modules/vite/package.json', '{}');
    $project->write('.cache/puppeteer/chrome/version', '1');
    $same = $project->worktree('feat/same');
    $changed = $project->worktree('feat/changed');
    file_put_contents("{$changed}/package-lock.json", '{"lockfileVersion":3,"packages":{"x":{}}}');
    TestProject::exec($changed, ['git', '-c', 'user.name=T', '-c', 'user.email=t@example.com', 'commit', '--quiet', '-am', 'Change lock']);

    $runner = new FakeRunner(fakes: ['npm' => function (array $command, string $cwd): Result {
        is_dir("{$cwd}/node_modules") || mkdir("{$cwd}/node_modules");

        return new Result(0, '');
    }], available: ['npm' => true]);

    lwt(['setup', '-n', '--npm'], $same, $runner);
    lwt(['setup', '-n', '--build'], $changed, $runner);

    expect(is_file("{$same}/node_modules/vite/package.json"))->toBeTrue()
        ->and(is_link("{$same}/node_modules"))->toBeFalse()
        ->and(is_file("{$same}/.cache/puppeteer/chrome/version"))->toBeTrue()
        ->and(is_file("{$changed}/node_modules/vite/package.json"))->toBeFalse()
        ->and($runner->ran('npm', 'ci'))->toBeTrue()
        ->and($runner->ran('npm', 'run', 'build'))->toBeTrue();
});

it('copies the gitignored paths .worktreeinclude lists', function () {
    $project = project();
    $project->write('.cache/certs/dev.pem', 'cert');
    $project->write('.worktreeinclude', "# shared with Claude Code\n.cache/certs\nREADME.md\n");
    $worktree = $project->worktree('feat/x');

    [$code, $output] = lwt(['setup', '-n'], $worktree);

    expect($code)->toBe(0)
        ->and(file_get_contents("{$worktree}/.cache/certs/dev.pem"))->toBe('cert')
        ->and($output)->toContain('.cache/certs — copied (.worktreeinclude)')
        ->and($output)->not->toContain('README.md — copied');
});

it('refuses the main checkout and a folder that isn\'t a worktree', function () {
    $project = project();

    [$main, $mainOutput] = lwt(['setup', '-n'], $project->path);
    [$other, $otherOutput] = lwt(['setup', '-n', $project->root], $project->path);

    expect($main)->toBe(1)
        ->and($mainOutput)->toContain('This is the main checkout')
        ->and($other)->toBe(1)
        ->and($otherOutput)->toContain('is not a worktree of this repository');
});

it('keeps what is already in place when run again', function () {
    $project = project();
    $worktree = $project->worktree('feat/x');
    lwt(['setup', '-n'], $worktree);
    file_put_contents("{$worktree}/.env", "APP_URL=http://mine.test\n");

    [$code, $output] = lwt(['setup', '-n'], $worktree);

    expect($code)->toBe(0)
        ->and(file_get_contents("{$worktree}/.env"))->toBe("APP_URL=http://mine.test\n")
        ->and($output)->toContain('vendor — already there, kept');
});
