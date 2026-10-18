<?php

use Laravel\Prompts\Key;
use Laravel\Prompts\Prompt;
use Phattarachai\WorktreePrompt\Application;
use Phattarachai\WorktreePrompt\Runner;
use Phattarachai\WorktreePrompt\Tests\FakeRunner;
use Phattarachai\WorktreePrompt\Tests\TestProject;
use Symfony\Component\Console\Output\BufferedOutput;

foreach (['GIT_CONFIG_GLOBAL' => '/dev/null', 'GIT_CONFIG_NOSYSTEM' => '1'] as $name => $value) {
    putenv("{$name}={$value}");
    $_SERVER[$name] = $_ENV[$name] = $value;
}

uses()->beforeEach(function () {
    Runner::$interactive = false;
    $this->runner = new FakeRunner;
})->afterEach(fn () => TestProject::cleanUp())->in(__DIR__);

function project(): TestProject
{
    return TestProject::create();
}

/**
 * Run `lwt`; returns the exit code and everything printed.
 *
 * @param  list<string>  $arguments
 * @return array{0: int, 1: string}
 */
function lwt(array $arguments, string $cwd, ?FakeRunner $runner = null): array
{
    $output = new BufferedOutput;
    Prompt::setOutput($output);

    ob_start();
    $code = (new Application($runner ?? test()->runner))->run($arguments, $cwd);
    $echoed = (string) ob_get_clean();

    return [$code, $echoed.$output->fetch()];
}

/**
 * Fake the terminal; each string is typed character by character, Key constants are pressed as-is.
 *
 * @param  list<string>  $inputs
 */
function answer(array $inputs): void
{
    $keys = [];

    foreach ($inputs as $input) {
        array_push($keys, ...(str_starts_with($input, "\e") || in_array($input, [Key::ENTER, Key::SPACE, Key::BACKSPACE, Key::TAB], true) ? [$input] : mb_str_split($input)));
    }

    Prompt::fake($keys);
    Runner::$interactive = true;
}
