<?php

use Phattarachai\WorktreePrompt\Tests\TestProject;

it('refuses a worktree with uncommitted or untracked files', function () {
    $project = project();
    $worktree = $project->worktree('feat/x');
    file_put_contents("{$worktree}/notes.txt", 'wip');

    [$code, $output] = lwt(['remove', 'feat/x', '-n'], $project->path);

    expect($code)->toBe(1)
        ->and($output)->toContain('1 uncommitted or untracked file(s)')
        ->and(is_dir($worktree))->toBeTrue();
});

it('removes a set-up worktree (vendor, .env and all), deletes its merged branch, prunes and drops the emptied folder', function () {
    $project = project();
    $worktree = $project->worktree('feat/done', '.claude/worktrees/done');
    lwt(['setup', '-n'], $worktree);

    [$code, $output] = lwt(['remove', 'done', '-n', '--delete-branch'], $project->path);

    expect($code)->toBe(0)
        ->and(is_dir($worktree))->toBeFalse()
        ->and(is_dir("{$project->root}/.claude/worktrees"))->toBeFalse()
        ->and(is_dir("{$project->root}/.claude"))->toBeTrue()
        ->and($project->git('branch', '--list', 'feat/done'))->toBe('')
        ->and($output)->toContain('git worktree prune');
});

it('keeps the branch unless asked, and an unmerged one even when asked', function () {
    $project = project();
    $kept = $project->worktree('feat/kept');
    $unmerged = $project->worktree('feat/unmerged');
    file_put_contents("{$unmerged}/work.txt", 'work');
    TestProject::exec($unmerged, ['git', 'add', '--all']);
    TestProject::exec($unmerged, ['git', '-c', 'user.name=T', '-c', 'user.email=t@example.com', 'commit', '--quiet', '-m', 'Work']);

    lwt(['remove', $kept, '-n'], $project->path);
    [, $output] = lwt(['remove', 'feat/unmerged', '-n', '--delete-branch'], $project->path);

    expect($project->git('branch', '--list', 'feat/kept'))->not->toBe('')
        ->and($project->git('branch', '--list', 'feat/unmerged'))->not->toBe('')
        ->and($output)->toContain('has 1 commit(s)')
        ->and($output)->toContain('Kept feat/unmerged');
});

it('drops the database lwt made, never the main checkout\'s', function () {
    $project = project();
    $shared = "{$project->root}/databases";
    mkdir($shared);
    file_put_contents("{$shared}/app.sqlite", 'main data');
    file_put_contents("{$project->path}/.env", "DB_CONNECTION=sqlite\nDB_DATABASE={$shared}/app.sqlite\n");
    $worktree = $project->worktree('feat/db');
    lwt(['setup', '-n', '--db=copy'], $worktree);
    $copy = "{$shared}/app_app_db.sqlite";

    expect(file_get_contents($copy))->toBe('main data')
        ->and(file_get_contents("{$worktree}/.env"))->toContain("DB_DATABASE={$copy}");

    [$code, $output] = lwt(['remove', 'feat/db', '-n', '--drop-db'], $project->path);

    expect($code)->toBe(0)
        ->and(file_exists($copy))->toBeFalse()
        ->and(file_get_contents("{$shared}/app.sqlite"))->toBe('main data')
        ->and($output)->toContain('database — dropped');
});

it('keeps the database it made without --drop-db', function () {
    $project = project();
    $shared = "{$project->root}/databases";
    mkdir($shared);
    file_put_contents("{$shared}/app.sqlite", 'main data');
    file_put_contents("{$project->path}/.env", "DB_CONNECTION=sqlite
DB_DATABASE={$shared}/app.sqlite
");
    $worktree = $project->worktree('feat/db');
    lwt(['setup', '-n', '--db=copy'], $worktree);

    [$code, $output] = lwt(['remove', 'feat/db', '-n'], $project->path);

    expect($code)->toBe(0)
        ->and(file_exists("{$shared}/app_app_db.sqlite"))->toBeTrue()
        ->and($output)->toContain('database — kept')
        ->and($output)->toContain('branch — kept feat/db');
});

it('prunes a worktree whose folder was deleted by hand', function () {
    $project = project();
    $worktree = $project->worktree('feat/gone');
    TestProject::exec($project->root, ['rm', '-rf', $worktree]);

    [$code] = lwt(['remove', 'feat/gone', '-n', '--delete-branch'], $project->path);

    expect($code)->toBe(0)
        ->and($project->git('worktree', 'list'))->not->toContain('app-gone')
        ->and($project->git('branch', '--list', 'feat/gone'))->toBe('');
});

it('removes a clean worktree without --force', function () {
    $project = project();
    $project->worktree('feat/plain');

    [$code] = lwt(['remove', 'feat/plain', '-n'], $project->path);

    expect($code)->toBe(0)
        ->and($this->runner->ran('git', 'worktree', 'remove', '--force'))->toBeFalse();
});

it('removes a worktree whose submodules were never initialized without --force', function () {
    $project = project();
    $project->submodule();
    $worktree = $project->worktree('feat/sub');

    [$code] = lwt(['remove', 'feat/sub', '-n'], $project->path);

    expect($code)->toBe(0)
        ->and(is_dir($worktree))->toBeFalse()
        ->and($this->runner->ran('git', 'worktree', 'remove', '--force'))->toBeFalse();
});

it('removes a worktree with a clean, pushed submodule using --force, only because git insists', function () {
    $project = project();
    $project->submodule();
    $worktree = $project->worktree('feat/sub');
    TestProject::initSubmodules($worktree);

    [$code, $output] = lwt(['remove', 'feat/sub', '-n'], $project->path);

    expect($code)->toBe(0)
        ->and($output)->toContain('git worktree remove --force')
        ->and(is_dir($worktree))->toBeFalse()
        ->and($project->git('worktree', 'list'))->not->toContain('app-sub');
});

it('checks the worktree itself before using --force', function () {
    $project = project();
    $project->submodule();
    $worktree = $project->worktree('feat/sub');
    TestProject::initSubmodules($worktree);
    file_put_contents("{$worktree}/notes.txt", 'wip');

    [$code, $output] = lwt(['remove', 'feat/sub', '-n'], $project->path);

    expect($code)->toBe(1)
        ->and($output)->toContain('1 uncommitted or untracked file(s): notes.txt')
        ->and(is_file("{$worktree}/notes.txt"))->toBeTrue();
});

it('refuses, and names what would be lost in a submodule', function (Closure $spoil, string $named) {
    $project = project();
    $project->submodule();
    $worktree = $project->worktree('feat/sub');
    TestProject::initSubmodules($worktree);
    $spoil("{$worktree}/lib", $worktree);

    [$code, $output] = lwt(['remove', 'feat/sub', '-n'], $project->path);

    expect($code)->toBe(1)
        ->and($output)->toContain($named)
        ->and($this->runner->ran('git', 'worktree', 'remove'))->toBeFalse()
        ->and(is_dir($worktree))->toBeTrue();
})->with([
    'a changed file' => [fn (string $lib) => file_put_contents("{$lib}/lib.txt", "changed\n"), 'lib has 1 uncommitted or untracked file(s): lib.txt'],
    'an untracked file' => [fn (string $lib) => file_put_contents("{$lib}/new.txt", 'new'), 'lib has 1 uncommitted or untracked file(s): new.txt'],
    'a commit on HEAD' => [fn (string $lib) => TestProject::commit($lib, 'work.txt'), 'lib has 1 commit(s) no remote has'],
    'a commit on a branch it left' => [function (string $lib) {
        TestProject::exec($lib, ['git', 'switch', '--quiet', '-c', 'wip']);
        TestProject::commit($lib, 'work.txt');
        TestProject::exec($lib, ['git', 'switch', '--quiet', '--detach', 'origin/main']);
    }, 'lib has 1 commit(s) no remote has'],
    'a stash' => [function (string $lib) {
        file_put_contents("{$lib}/lib.txt", "changed\n");
        TestProject::exec($lib, ['git', '-c', 'user.name=T', '-c', 'user.email=t@example.com', 'stash', '--quiet']);
    }, 'lib has a stash'],
    'a commit it moved to without committing that here' => [function (string $lib) {
        TestProject::commit($lib, 'work.txt');
        TestProject::exec($lib, ['git', 'push', '--quiet', 'origin', 'HEAD:refs/heads/pushed']);
    }, 'lib is on a commit this worktree hasn\'t committed'],
    'a commit in a submodule since deinitialized' => [function (string $lib, string $worktree) {
        TestProject::commit($lib, 'work.txt');
        TestProject::exec($lib, ['git', 'switch', '--quiet', '-c', 'wip']);
        TestProject::exec($lib, ['git', 'switch', '--quiet', '--detach', 'origin/main']);
        TestProject::exec($worktree, ['git', 'submodule', '--quiet', 'deinit', 'lib']);
    }, 'lib (not checked out) has 1 commit(s) no remote has'],
]);

it('looks into nested submodules too', function () {
    $project = project();
    $project->submodule();
    $lib = "{$project->root}/lib-checkout";
    TestProject::exec($project->root, ['git', 'clone', '--quiet', 'lib.git', 'lib-checkout']);
    $project->submodule('inner', 'inner', $lib);
    $worktree = $project->worktree('feat/nested');
    TestProject::exec("{$worktree}", ['git', '-c', 'protocol.file.allow=always', 'submodule', '--quiet', 'update', '--init', '--remote', 'lib']);
    TestProject::initSubmodules("{$worktree}/lib");
    TestProject::commit("{$worktree}/lib", 'pin.txt');
    TestProject::exec("{$worktree}/lib", ['git', 'push', '--quiet', 'origin', 'HEAD:refs/heads/pushed']);
    TestProject::commit($worktree, 'pin.txt');
    TestProject::commit("{$worktree}/lib/inner", 'work.txt');

    [$code, $output] = lwt(['remove', 'feat/nested', '-n'], $project->path);

    expect($code)->toBe(1)
        ->and($output)->toContain('lib/inner has 1 commit(s) no remote has')
        ->and(is_dir($worktree))->toBeTrue();
});

it('finds the repository from a worktree\'s path, whatever folder it runs from', function (Closure $from) {
    $project = project();
    $worktree = $project->worktree('feat/far');

    [$code, $output] = lwt(['remove', $worktree, '-n', '--delete-branch'], $from($project->root));

    expect($code)->toBe(0)
        ->and($output)->toContain('removed')
        ->and(is_dir($worktree))->toBeFalse()
        ->and($project->git('branch', '--list', 'feat/far'))->toBe('');
})->with([
    'outside any repository' => [function (string $root) {
        mkdir("{$root}/plain");

        return "{$root}/plain";
    }],
    'in another repository' => [function (string $root) {
        TestProject::exec($root, ['git', 'init', '--quiet', 'unrelated']);

        return "{$root}/unrelated";
    }],
    'a folder that no longer exists' => [fn () => ''],
]);

it('says what to do when a branch name can\'t be looked up from here', function (Closure $from, string $message) {
    $project = project();
    $worktree = $project->worktree('feat/far');

    [$code, $output] = lwt(['remove', 'feat/far', '-n'], $from($project->root));

    expect($code)->toBe(1)
        ->and(str_replace("\n", ' ', (string) preg_replace('/\s+/', ' ', $output)))->toContain($message)
        ->and(is_dir($worktree))->toBeTrue();
})->with([
    'outside any repository' => [function (string $root) {
        mkdir("{$root}/plain");

        return "{$root}/plain";
    }, 'Not inside a git repository — cd into the project'],
    'in another repository' => [function (string $root) {
        TestProject::exec($root, ['git', 'init', '--quiet', 'unrelated']);
        TestProject::exec("{$root}/unrelated", ['git', '-c', 'user.name=T', '-c', 'user.email=t@example.com', 'commit', '--quiet', '--allow-empty', '-m', 'Init']);

        return "{$root}/unrelated";
    }, 'unrelated has no worktrees besides the main checkout'],
    'a folder that no longer exists' => [fn () => '', 'The current folder no longer exists — cd into the project'],
]);
