<?php

namespace Phattarachai\WorktreePrompt;

use RuntimeException;

/**
 * Something the user can fix; printed as one red line, no trace.
 */
final class UserError extends RuntimeException {}
