<?php

namespace Phattarachai\WorktreePrompt;

/**
 * A `.env` file edited line by line: comments, order and every key lwt doesn't touch stay as they were.
 */
final class EnvFile
{
    /**
     * @param  list<string>  $lines
     */
    private function __construct(public readonly string $path, private array $lines) {}

    public static function read(string $path): self
    {
        $contents = is_file($path) ? (string) file_get_contents($path) : '';

        return new self($path, $contents === '' ? [] : explode("\n", rtrim($contents, "\n")));
    }

    public function get(string $key): ?string
    {
        $value = null;

        foreach ($this->lines as $line) {
            if (preg_match('/^\s*(?:export\s+)?'.preg_quote($key, '/').'\s*=(.*)$/', $line, $match) === 1) {
                $value = self::unquote(trim($match[1]));
            }
        }

        return $value;
    }

    /**
     * @return array<string, string>
     */
    public function all(): array
    {
        $values = [];

        foreach ($this->lines as $line) {
            if (preg_match('/^\s*(?:export\s+)?([A-Za-z_][A-Za-z0-9_]*)\s*=(.*)$/', $line, $match) === 1) {
                $values[$match[1]] = self::unquote(trim($match[2]));
            }
        }

        return $values;
    }

    public function set(string $key, string $value): self
    {
        $line = $key.'='.self::quote($value);

        foreach ($this->lines as $index => $existing) {
            if (preg_match('/^\s*(?:export\s+)?'.preg_quote($key, '/').'\s*=/', $existing) === 1) {
                $this->lines[$index] = $line;

                return $this;
            }
        }

        $this->lines[] = $line;

        return $this;
    }

    /**
     * Never through a symlink: a symlinked `.env` is the main checkout's file.
     */
    public function save(): void
    {
        if (is_link($this->path)) {
            throw new UserError("{$this->path} is a symlink — lwt won't edit the main checkout's .env through it.");
        }

        file_put_contents($this->path, implode("\n", $this->lines)."\n");
    }

    private static function unquote(string $value): string
    {
        if (preg_match('/^"((?:[^"\\\\]|\\\\.)*)"/', $value, $match) === 1) {
            return stripcslashes($match[1]);
        }

        if (preg_match("/^'([^']*)'/", $value, $match) === 1) {
            return $match[1];
        }

        return trim((string) preg_replace('/\s+#.*$/', '', $value));
    }

    private static function quote(string $value): string
    {
        if (preg_match('/^[\w.\/:@,+-]*$/', $value) === 1) {
            return $value;
        }

        return '"'.addcslashes($value, '"\\').'"';
    }
}
