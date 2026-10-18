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
