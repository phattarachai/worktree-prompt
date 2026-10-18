<?php

use Laravel\Prompts\Key;
use Phattarachai\WorktreePrompt\Tests\TestProject;

it('branches from origin/main with --no-track, beside the main checkout, and sets it up', function () {
    $project = project();

    [$code, $output] = lwt(['add', 'fix/login-redirect', '-n'], $project->path);
    $path = "{$project->root}/app-login-redirect";

    expect($code)->toBe(0)
        ->and($output)->toContain('git worktree add --no-track -b fix/login-redirect')
        ->and($output)->toContain('new branch fix/login-redirect from origin/main')
        ->and(TestProject::exec($path, ['git', 'symbolic-ref', '--short', 'HEAD']))->toBe('fix/login-redirect')
        ->and(TestProject::exec($path, ['git', 'for-each-ref', '--format=%(upstream)', 'refs/heads/fix/login-redirect']))->toBe('')
        ->and(is_file("{$path}/vendor/autoload.php"))->toBeTrue()
        ->and(is_file("{$path}/.env"))->toBeTrue()
        ->and($output)->toContain('cd '.str_replace(getenv('HOME').'/', '~/', $path));
});

it('fetches first, and checks out a branch that only exists on origin tracking it', function () {
    $project = project();
    $project->pushFromElsewhere('feat/export', 'export.txt', "pdf\n");

    [$code, $output] = lwt(['add', 'feat/export', '-n', "--path={$project->root}/export"], $project->path);

    expect($code)->toBe(0)
        ->and($output)->toContain('git fetch --all --prune')
        ->and($output)->toContain('feat/export, tracking origin/feat/export')
        ->and(file_get_contents("{$project->root}/export/export.txt"))->toBe("pdf\n")
        ->and(TestProject::exec($project->path, ['git', 'for-each-ref', '--format=%(upstream:short)', 'refs/heads/feat/export']))->toBe('origin/feat/export');
});

it('branches from the --base given', function () {
    $project = project();
    $project->pushFromElsewhere('develop', 'develop.txt', "dev\n");

    [$code] = lwt(['add', 'feat/on-develop', '-n', '--base=origin/develop'], $project->path);

    expect($code)->toBe(0)
        ->and(file_exists("{$project->root}/app-on-develop/develop.txt"))->toBeTrue();
});

it('refuses a branch checked out in another worktree, and a folder that is in use', function () {
    $project = project();
    $project->worktree('feat/busy');
    mkdir("{$project->root}/taken");
    file_put_contents("{$project->root}/taken/file", 'x');

    [$busy, $busyOutput] = lwt(['add', 'feat/busy', '-n'], $project->path);
    [$taken, $takenOutput] = lwt(['add', 'feat/new', '-n', "--path={$project->root}/taken"], $project->path);

    expect($busy)->toBe(1)
        ->and($busyOutput)->toContain('already checked out at')
        ->and($taken)->toBe(1)
        ->and($takenOutput)->toContain('already exists and isn\'t empty');
});

it('needs a branch name without a terminal', function () {
    $project = project();

    [$code, $output] = lwt(['add', '-n'], $project->path);

    expect($code)->toBe(1)->and($output)->toContain('lwt add <branch>');
});

it('asks for the branch, base, folder and setup when there is a terminal', function () {
    $project = project();

    answer([
        'fix/typo', Key::ENTER,     // Branch
        Key::ENTER,                 // Branch from: origin/main
        Key::ENTER,                 // Folder: ../app-typo
        Key::ENTER,                 // Database: shared
        Key::ENTER,                 // APP_URL: http://app-typo.test
        Key::ENTER,                 // node_modules: skip
    ]);

    [$code, $output] = lwt(['add', '--no-fetch'], $project->path);
    $path = "{$project->root}/app-typo";

    expect($code)->toBe(0)
        ->and($output)->toContain('new branch fix/typo from origin/main')
        ->and(file_get_contents("{$path}/.env"))->toContain('APP_URL=http://app-typo.test')
        ->and(file_get_contents("{$path}/.env"))->toContain('SESSION_DOMAIN=app-typo.test');
});
