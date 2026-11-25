<?php

namespace Phattarachai\WorktreePrompt;

use Closure;
use PDO;

/**
 * Every database server connection is opened here, so tests can stand in for a server a CI box doesn't have.
 */
final class Connector
{
    /** @var ?Closure(string, string, string): PDO */
    public static ?Closure $using = null;

    /**
     * @param  array<int, mixed>  $options
     */
    public static function open(string $dsn, string $username, string $password, array $options): PDO
    {
        return self::$using === null ? new PDO($dsn, $username, $password, $options) : (self::$using)($dsn, $username, $password);
    }
}
