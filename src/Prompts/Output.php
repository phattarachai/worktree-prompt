<?php

namespace Phattarachai\WorktreePrompt\Prompts;

use Laravel\Prompts\Prompt;

/**
 * Plain lines through the same output Prompts writes to, so a faked
 * terminal in tests captures them too.
 */
final class Output extends Prompt
{
    public static function writeln(string $message): void
    {
        self::output()->writeln($message);
    }

    public function value(): mixed
    {
        return null;
    }
}
