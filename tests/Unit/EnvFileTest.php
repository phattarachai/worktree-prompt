<?php

use Phattarachai\WorktreePrompt\EnvFile;
use Phattarachai\WorktreePrompt\Tests\TestProject;

it('reads quoted, commented and exported values', function () {
    $file = tempnam(sys_get_temp_dir(), 'env');
    file_put_contents($file, "# app\nAPP_NAME=\"My App\"\nexport DB_HOST=127.0.0.1\nDB_DATABASE=shop # local\nMAIL_FROM='a@b.test'\nEMPTY=\n");

    $env = EnvFile::read($file);

    expect($env->get('APP_NAME'))->toBe('My App')
        ->and($env->get('DB_HOST'))->toBe('127.0.0.1')
        ->and($env->get('DB_DATABASE'))->toBe('shop')
        ->and($env->get('MAIL_FROM'))->toBe('a@b.test')
        ->and($env->get('EMPTY'))->toBe('')
        ->and($env->get('MISSING'))->toBeNull();

    unlink($file);
});

it('changes one line and keeps every other line as it was', function () {
    $file = tempnam(sys_get_temp_dir(), 'env');
    file_put_contents($file, "# app\nAPP_URL=http://shop.test\nDB_DATABASE=shop # local\n");

    EnvFile::read($file)->set('DB_DATABASE', 'shop_login')->set('APP_NAME', 'Shop Login')->save();

    expect(file_get_contents($file))->toBe("# app\nAPP_URL=http://shop.test\nDB_DATABASE=shop_login\nAPP_NAME=\"Shop Login\"\n");

    unlink($file);
});

it('refuses to save through a symlink', function () {
    $project = project();
    symlink("{$project->path}/.env", "{$project->root}/linked.env");

    EnvFile::read("{$project->root}/linked.env")->set('APP_URL', 'http://other.test')->save();
})->throws(RuntimeException::class, 'is a symlink');

afterEach(fn () => TestProject::cleanUp());
