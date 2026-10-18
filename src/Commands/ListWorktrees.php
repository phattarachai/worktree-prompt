<?php

namespace Phattarachai\WorktreePrompt\Commands;

use Phattarachai\WorktreePrompt\Git;
use Phattarachai\WorktreePrompt\State;

/**
 * `lwt list`: every worktree, whether it is set up, and what lwt made for it.
 */
class ListWorktrees extends Command
{
    public function __invoke(array $args): int
    {
        $this->mainCheckout();

        foreach ($this->git->worktrees() as $index => $worktree) {
            $path = $worktree['path'];
            $state = is_dir($path) ? State::read(new Git($this->runner, $path)) : [];
            $notes = array_filter([
                $index === 0 ? 'main' : null,
                ! is_dir($path) ? 'folder missing — lwt remove prunes it' : null,
                is_dir($path) && $index > 0 && ! is_file("{$path}/vendor/autoload.php") ? 'not set up — lwt setup' : null,
                is_link("{$path}/vendor") ? 'vendor is a symlink!' : null,
                isset($state['database']) ? "db {$state['database']['name']}" : null,
            ]);

            $this->console->line(sprintf('  %s <fg=cyan>%s</>%s', $path, $worktree['branch'] ?? 'detached', $notes === [] ? '' : ' <fg=gray>· '.implode(' · ', $notes).'</>'));
        }

        return 0;
    }
}
