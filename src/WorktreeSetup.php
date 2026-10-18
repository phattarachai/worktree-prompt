<?php

namespace Phattarachai\WorktreePrompt;

use PDOException;

use function Laravel\Prompts\confirm;

/**
 * Makes a worktree of a Laravel project runnable: everything git doesn't carry (vendor, .env, storage,
 * build output, node_modules, optionally a database of its own) comes from the main checkout or gets
 * installed, and nothing git does carry is left changed.
 */
final class WorktreeSetup
{
    /** @var list<string> gitignored in a stock Laravel app, and Blade renders die without them */
    private const array STORAGE = [
        'storage/app/public', 'storage/app/private', 'storage/framework/cache/data', 'storage/framework/sessions',
        'storage/framework/testing', 'storage/framework/views', 'storage/logs', 'bootstrap/cache',
    ];

    /** @var list<string> build output a test or a page render reads; public/hot is left out, it points at the main checkout's Vite */
    private const array BUILD_OUTPUT = ['public/build', 'bootstrap/ssr'];

    /** @var array<string, array{0: list<string>, 1: string}> lockfile => [clean install, package manager] */
    private const array NODE_LOCKFILES = [
        'package-lock.json' => [['npm', 'ci'], 'npm'],
        'pnpm-lock.yaml' => [['pnpm', 'install', '--frozen-lockfile'], 'pnpm'],
        'yarn.lock' => [['yarn', 'install', '--frozen-lockfile'], 'yarn'],
        'bun.lock' => [['bun', 'install', '--frozen-lockfile'], 'bun'],
        'bun.lockb' => [['bun', 'install', '--frozen-lockfile'], 'bun'],
    ];

    private readonly Git $git;

    /** @var array<string, string> label => value, printed at the end */
    private array $summary = [];

    /** @var list<string> what was asked for and didn't happen; any of them fails the setup */
    private array $problems = [];

    public function __construct(private readonly Console $console, private readonly string $main, private readonly string $path)
    {
        $this->git = new Git($console->runner, $path);
    }

    public function __invoke(SetupOptions $options): bool
    {
        $options->validate();

        if (realpath($this->path) === realpath($this->main)) {
            throw new UserError('This is the main checkout — lwt sets up the other worktrees from it.');
        }

        if (! is_file("{$this->path}/artisan")) {
            throw new UserError("{$this->path} has no artisan file — lwt is for Laravel projects.");
        }

        $before = $this->git->status();
        $this->console->line("  <options=bold>Setting up</> {$this->path}");

        $this->env($options);
        $this->storage();
        $vendor = $this->vendor($options);
        $this->url($options);
        $database = $this->database($options);
        $this->buildOutput();
        $node = $this->node($options);
        $this->worktreeInclude();
        $this->storageLink();

        if ($vendor && $database !== null) {
            $this->migrate($database, $options);
        }

        if ($options->build && $node !== null) {
            $this->build($node);
        }

        $this->restoreTracked($before);
        $verified = $vendor && $this->verify();
        $this->testDatabase();
        $this->printSummary();

        return $verified && $this->problems === [];
    }

    private function problem(string $message): void
    {
        $this->problems[] = $message;
        $this->console->warn($message);
    }

    private function env(SetupOptions $options): void
    {
        $env = "{$this->path}/.env";

        if ($options->env === 'skip') {
            $this->console->skip('.env — skipped');
        } elseif (! is_file("{$this->main}/.env")) {
            $this->console->warn("The main checkout has no .env — create one in {$this->path} yourself.");
        } elseif (is_link($env) && $options->env === 'copy') {
            unlink($env);
            $this->console->run(['cp', '-p', "{$this->main}/.env", '.env'], $this->path);
            $this->console->done('.env — replaced the symlink with a copy, so this worktree can change it');
        } elseif (file_exists($env) || is_link($env)) {
            $this->console->skip('.env — already there, kept');
        } elseif ($options->env === 'symlink') {
            $this->console->run(['ln', '-s', "{$this->main}/.env", '.env'], $this->path);
            $this->console->done('.env — symlinked to the main checkout\'s');
        } else {
            $this->console->run(['cp', '-p', "{$this->main}/.env", '.env'], $this->path);
            $this->console->done('.env — copied from the main checkout');
        }

        if ($options->env === 'skip') {
            return;
        }

        foreach (glob("{$this->main}/.env*") ?: [] as $file) {
            $name = basename($file);

            if (in_array($name, ['.env', '.env.example'], true) || ! is_file($file) || file_exists("{$this->path}/{$name}")) {
                continue;
            }

            if ($this->git->isTracked($name) || ! $this->git->isIgnored($name)) {
                continue;
            }

            $this->console->run(['cp', '-p', $file, $name], $this->path);
            $this->console->done("{$name} — copied (gitignored, so the branch never had it)");
        }
    }

    private function storage(): void
    {
        $missing = array_values(array_filter(self::STORAGE, fn (string $dir) => ! is_dir("{$this->path}/{$dir}")));

        if ($missing === []) {
            $this->console->skip('storage/ — already there');

            return;
        }

        $this->console->run(['mkdir', '-p', ...$missing], $this->path);
        $this->console->done('storage/ and bootstrap/cache — created the folders git doesn\'t keep');
    }

    private function vendor(SetupOptions $options): bool
    {
        $vendor = "{$this->path}/vendor";

        if (! is_file("{$this->path}/composer.json") || $options->vendor === 'skip') {
            $this->console->skip('vendor — skipped');

            return is_file("{$vendor}/autoload.php");
        }

        if (is_link($vendor)) {
            unlink($vendor);
            $this->console->warn('vendor was a symlink — removed: Composer\'s autoloader resolves it to the main checkout, so it ran the main checkout\'s app/');
        }

        if (is_file("{$vendor}/autoload.php")) {
            $this->console->skip('vendor — already there, kept');

            return true;
        }

        $sameLock = Files::sameContents("{$this->main}/composer.lock", "{$this->path}/composer.lock");
        $canCopy = $sameLock && is_file("{$this->main}/vendor/autoload.php") && ! is_link("{$this->main}/vendor");
        $copy = $options->vendor === 'copy' || ($options->vendor === 'auto' && $canCopy);

        if ($copy && ! is_dir("{$this->main}/vendor")) {
            throw new UserError('The main checkout has no vendor to copy — use --vendor=install.');
        }

        if ($copy) {
            $copied = Files::copy($this->console, "{$this->main}/vendor", $vendor, $this->path);
            $copied && $this->console->done('vendor — a real copy of the main checkout\'s'.($sameLock ? ' (composer.lock is the same)' : '').', never a symlink');

            return $copied;
        }

        $result = $this->console->run(['composer', 'install', '--no-interaction', '--no-progress'], $this->path, 'composer install');

        if ($result->failed()) {
            $this->console->warn('composer install failed — fix it, then run lwt setup again.');

            return false;
        }

        $this->console->done('vendor — composer install'.($sameLock ? '' : ' (composer.lock differs from the main checkout\'s)'));

        return true;
    }

    private function url(SetupOptions $options): void
    {
        $file = "{$this->path}/.env";

        if ($options->url === null || ! is_file($file) || is_link($file)) {
            return;
        }

        $env = EnvFile::read($file);
        $oldHost = (string) parse_url((string) $env->get('APP_URL'), PHP_URL_HOST);
        $newHost = (string) parse_url($options->url, PHP_URL_HOST);
        $env->set('APP_URL', $options->url);
        $changed = ['APP_URL'];

        foreach ($oldHost === '' || $newHost === '' ? [] : $env->all() as $key => $value) {
            $follows = in_array($key, ['SESSION_DOMAIN', 'SANCTUM_STATEFUL_DOMAINS'], true)
                || (str_ends_with($key, '_URL') && preg_match('/^(APP|DB|DATABASE|REDIS|MAIL|AWS|SENTRY|WATCHTOWER)_/', $key) !== 1);

            if ($follows && str_contains($value, $oldHost)) {
                $env->set($key, str_replace($oldHost, $newHost, $value));
                $changed[] = $key;
            }
        }

        $env->save();
        $this->console->done(implode(', ', $changed)." — {$options->url}");
        $this->summary['url'] = $options->url;
    }

    /**
     * Point this worktree's .env at a database of its own. Returns it when it should be migrated.
     */
    private function database(SetupOptions $options): ?Database
    {
        $file = "{$this->path}/.env";
        $env = EnvFile::read(is_file($file) ? $file : "{$this->main}/.env");
        $source = Database::fromEnv(EnvFile::read("{$this->main}/.env"), $this->main);

        $current = Database::fromEnv($env, $this->path);
        $this->summary['database'] = $current === null ? 'the main checkout\'s' : $current->describe().' — shared with the main checkout';

        if ($options->database === 'shared') {
            return null;
        }

        if (! is_file($file) || is_link($file)) {
            $this->problem('A database of its own needs a copied .env — kept the shared one.');

            return null;
        }

        if ($source === null) {
            $this->problem('DB_CONNECTION='.$env->get('DB_CONNECTION').' isn\'t pgsql, mysql, mariadb or sqlite — set DB_DATABASE in .env yourself.');

            return null;
        }

        if (! $source->isLocal() && ! (Runner::interactive() && confirm("DB_HOST={$source->host} is not this machine. Create a database there anyway?", default: false))) {
            $this->problem("DB_HOST={$source->host} is not this machine — kept the shared database. Point .env at a local server first.");

            return null;
        }

        $target = $this->targetDatabase($source, $env, $options)->administeredBy($options->databaseAdmin);
        $state = State::read($this->git);

        if ($source->isSqlite() && $target->database === $source->database) {
            throw new UserError('DB_DATABASE points at the main checkout\'s SQLite file — lwt would share it, not copy it.');
        }

        try {
            $exists = $target->exists();
            $made = ($state['database']['name'] ?? null) === $target->database;

            if ($exists && ! $made && ! $target->isSqlite()) {
                $this->console->warn("{$target->database} already exists and lwt didn't make it — using it as is, and lwt remove won't drop it.");
            }

            if (! $exists) {
                $target->create();

                // A SQLite file inside the worktree goes with the folder; anything else lwt remove drops.
                if (! str_starts_with($target->database, $this->path.'/')) {
                    State::merge($this->git, ['database' => array_filter(['driver' => $target->driver, 'name' => $target->database, 'host' => $target->host, 'port' => $target->port, 'admin' => $target->admin], fn (?string $value) => $value !== null)]);
                }

                $this->console->done("database — created {$target->describe()}");
            }
        } catch (PDOException $exception) {
            $hint = Database::isPermissionError($exception) && $target->admin === null ? " Rerun with --db-admin=root (or postgres): it creates the database and grants it to {$target->username}." : '';
            $this->problem("Could not create {$target->database}: {$exception->getMessage()} — kept the shared database.{$hint}");

            return null;
        }

        // A relative SQLite path (or none: database/database.sqlite) already resolves inside this worktree.
        if (! $target->isSqlite() || str_starts_with((string) $env->get('DB_DATABASE'), '/')) {
            $env->set('DB_DATABASE', $target->database)->save();
        }

        if ($options->database === 'copy' && ! $exists) {
            $this->copyData($source, $target);
        }

        $this->summary['database'] = $target->describe().($options->database === 'copy' ? " — a copy of {$source->database}" : ' — its own, migrated');

        return $target;
    }

    private function targetDatabase(Database $source, EnvFile $env, SetupOptions $options): Database
    {
        $slug = $options->slug ?? basename($this->path);

        if (! $source->isSqlite()) {
            return $source->named(Database::nameFor($source->database, $slug));
        }

        $relative = str_starts_with($source->database, $this->main.'/') ? substr($source->database, strlen($this->main) + 1) : null;

        return $source->named($relative !== null
            ? "{$this->path}/{$relative}"
            : dirname($source->database).'/'.Database::nameFor(pathinfo($source->database, PATHINFO_FILENAME), $slug).'.sqlite');
    }

    private function copyData(Database $source, Database $target): void
    {
        $copy = $source->copyTo($target);
        $missing = array_values(array_filter($copy['tools'], fn (string $tool) => ! $this->console->runner->has($tool)));

        if ($missing !== []) {
            $this->problem('Copying the data needs '.implode(' and ', $missing).' on your PATH — the new database is empty; migrations will run on it.');

            return;
        }

        $result = $this->console->shell($copy['script'], $this->path, $copy['shown'], "Copying {$source->database} into {$target->database}", $copy['env']);

        $result->successful()
            ? $this->console->done("data — copied {$source->database} into {$target->database}")
            : $this->problem("Copying into {$target->database} failed part-way — check it before relying on it, or lwt remove and try again.");
    }

    private function migrate(Database $database, SetupOptions $options): void
    {
        $env = EnvFile::read("{$this->path}/.env");

        if ($env->get('APP_ENV') === 'production' || is_link("{$this->path}/.env")) {
            $this->problem('Not migrating: this .env says production, or is the main checkout\'s.');

            return;
        }

        $command = ['php', 'artisan', 'migrate', '--force', '--no-interaction', ...($options->seed && $options->database === 'empty' ? ['--seed'] : [])];
        $override = $database->isSqlite() ? [] : ['DB_DATABASE' => $database->database];
        $result = $this->console->run($command, $this->path, "Migrating {$database->database}", $override);

        $result->successful()
            ? $this->console->done("migrations — ran on {$database->database}")
            : $this->problem("php artisan migrate failed on {$database->database} — run it yourself once the cause is fixed.");
    }

    private function buildOutput(): void
    {
        foreach (self::BUILD_OUTPUT as $path) {
            if (! is_dir("{$this->main}/{$path}") || file_exists("{$this->path}/{$path}") || ! $this->git->isIgnored($path)) {
                continue;
            }

            Files::copy($this->console, "{$this->main}/{$path}", "{$this->path}/{$path}", $this->path)
                && $this->console->done("{$path} — copied from the main checkout (build again once the branch adds Tailwind classes or changes JS)");
        }
    }

    /**
     * @return ?string the package manager, when node_modules is in place
     */
    private function node(SetupOptions $options): ?string
    {
        if (! is_file("{$this->path}/package.json")) {
            return null;
        }

        if ($options->node === 'skip') {
            $this->console->skip('node_modules — skipped (lwt setup --npm adds it; only frontend builds need it)');

            return null;
        }

        [$install, $manager, $lockfile] = $this->nodeInstaller();
        $modules = "{$this->path}/node_modules";

        if (is_link($modules)) {
            unlink($modules);
            $this->console->warn('node_modules was a symlink — removed: an install in this worktree would have changed the main checkout\'s');
        }

        if (is_dir($modules)) {
            $this->console->skip('node_modules — already there, kept');

            return $manager;
        }

        $sameLock = $lockfile !== null && Files::sameContents("{$this->main}/{$lockfile}", "{$this->path}/{$lockfile}");
        $canCopy = $sameLock && is_dir("{$this->main}/node_modules") && ! is_link("{$this->main}/node_modules");
        $copy = $options->node === 'copy' || ($options->node === 'auto' && $canCopy);

        if ($copy && is_dir("{$this->main}/node_modules")) {
            if (! Files::copy($this->console, "{$this->main}/node_modules", $modules, $this->path)) {
                return null;
            }

            $this->console->done('node_modules — a copy of the main checkout\'s ('.($sameLock ? "{$lockfile} is the same" : 'as asked').')');
        } else {
            if (! $this->console->runner->has($manager)) {
                $this->problem("node_modules — {$manager} isn't on your PATH.");

                return null;
            }

            if ($this->console->run($install, $this->path, implode(' ', $install))->failed()) {
                $this->problem(implode(' ', $install).' failed.');

                return null;
            }

            $this->console->done('node_modules — '.implode(' ', $install));
        }

        $cache = '.cache/puppeteer';

        if (is_dir("{$this->main}/{$cache}") && ! file_exists("{$this->path}/{$cache}") && $this->git->isIgnored($cache)) {
            Files::copy($this->console, "{$this->main}/{$cache}", "{$this->path}/{$cache}", $this->path)
                && $this->console->done("{$cache} — copied, for headless Chrome");
        }

        return $manager;
    }

    /**
     * @return array{0: list<string>, 1: string, 2: ?string} clean-install command, package manager, lockfile
     */
    private function nodeInstaller(): array
    {
        foreach (self::NODE_LOCKFILES as $lockfile => [$install, $manager]) {
            if (is_file("{$this->path}/{$lockfile}")) {
                return [$install, $manager, $lockfile];
            }
        }

        return [['npm', 'install'], 'npm', null];
    }

    private function build(string $manager): void
    {
        $command = [$manager, 'run', 'build'];

        $this->console->run($command, $this->path, 'Building assets')->successful()
            ? $this->console->done(implode(' ', $command))
            : $this->problem(implode(' ', $command).' failed.');
    }

    /**
     * Copy what `.worktreeinclude` lists (the same file Claude Code reads): gitignored paths the
     * project needs in every worktree. Tracked and existing paths are never overwritten.
     */
    private function worktreeInclude(): void
    {
        $list = is_file("{$this->path}/.worktreeinclude") ? "{$this->path}/.worktreeinclude" : "{$this->main}/.worktreeinclude";

        if (! is_file($list)) {
            return;
        }

        foreach (file($list, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $pattern = trim(ltrim(trim($line), '/'), '/');

            if ($pattern === '' || str_starts_with($pattern, '#') || str_starts_with($pattern, '!')) {
                continue;
            }

            foreach (glob("{$this->main}/{$pattern}") ?: [] as $source) {
                $relative = substr($source, strlen($this->main) + 1);

                if (file_exists("{$this->path}/{$relative}") || ! $this->git->isIgnored($relative)) {
                    continue;
                }

                Files::copy($this->console, $source, "{$this->path}/{$relative}", $this->path)
                    && $this->console->done("{$relative} — copied (.worktreeinclude)");
            }
        }
    }

    private function storageLink(): void
    {
        $link = 'public/storage';

        if (! is_link("{$this->main}/{$link}") || file_exists("{$this->path}/{$link}") || is_link("{$this->path}/{$link}") || ! $this->git->isIgnored($link)) {
            return;
        }

        $this->console->run(['ln', '-s', "{$this->path}/storage/app/public", $link], $this->path);
        $this->console->done('public/storage — linked to this worktree\'s storage/app/public (empty: uploads stay in the main checkout)');
    }

    /**
     * Composer scripts republish assets and installs can rewrite lockfiles; put back every tracked file the
     * setup changed, and list the new untracked ones. Files that were already changed before are left alone.
     *
     * @param  array<string, string>  $before
     */
    private function restoreTracked(array $before): void
    {
        $changed = array_diff_assoc($this->git->status(), $before);
        $tracked = array_keys(array_filter($changed, fn (string $code) => $code !== '??'));
        $untracked = array_keys(array_filter($changed, fn (string $code) => $code === '??'));

        if ($tracked !== []) {
            $this->console->run(['git', 'restore', '--source=HEAD', '--staged', '--worktree', '--', ...$tracked], $this->path);
            $this->console->done('restored '.count($tracked).' tracked file(s) the setup changed: '.self::listed($tracked));
        }

        if ($untracked !== []) {
            $this->console->warn('The setup left '.count($untracked).' new file(s) git would commit: '.self::listed($untracked).' — delete or ignore them before committing.');
        }
    }

    /**
     * Prove the worktree runs its own code: Composer must load App\ from this folder, not the main checkout.
     */
    private function verify(): bool
    {
        $probe = '$l = require "vendor/autoload.php"; $d = $l->getPrefixesPsr4()["App\\\\"] ?? []; echo $d === [] ? "" : realpath($d[0]);';
        $app = trim($this->console->runner->run(['php', '-r', $probe], $this->path)->output);
        $root = (string) realpath($this->path);

        if ($app !== '' && ! str_starts_with($app.'/', $root.'/')) {
            $this->console->warn("App\\ loads from {$app}, not this worktree — vendor isn't this worktree's own.");

            return false;
        }

        $artisan = $this->console->runner->run(['php', 'artisan', '--version'], $this->path);

        if ($artisan->failed()) {
            $this->console->warn('php artisan --version failed:');
            $this->console->line('<fg=gray>    '.str_replace("\n", "\n    ", $artisan->allOutput()).'</>');

            return false;
        }

        $this->console->done(trim($artisan->output).($app === '' ? '' : ' — App\\ loads from this worktree'));

        return true;
    }

    /**
     * The database the tests use — .env.testing (or .env) overlaid with phpunit.xml's <env> — is shared by every
     * checkout and stays so (the projects' guard tests pin its name). Check that it is there and loaded.
     */
    private function testDatabase(): void
    {
        $env = EnvFile::read(is_file("{$this->path}/.env.testing") ? "{$this->path}/.env.testing" : "{$this->path}/.env")->all();
        $phpunit = is_file("{$this->path}/phpunit.xml") ? (string) file_get_contents("{$this->path}/phpunit.xml") : '';
        preg_match_all('/<env\s+name="(DB_[A-Z_]+)"\s+value="([^"]*)"/', $phpunit, $matches, PREG_SET_ORDER);

        foreach ($matches as [, $key, $value]) {
            $env[$key] = $value;
        }

        $database = Database::fromValues($env, $this->path);

        if ($database === null || $database->database === ':memory:') {
            return;
        }

        $snapshot = glob("{$this->path}/storage/app/snapshots/testing.sql*") !== [];
        $load = $snapshot ? 'php artisan snapshot:load testing --env=testing --force' : 'php artisan migrate --env=testing';

        try {
            $tables = $database->tableCount();
        } catch (PDOException $exception) {
            $this->console->warn("Couldn't reach the tests' database {$database->database}: {$exception->getMessage()}");

            return;
        }

        if (! $tables) {
            $this->console->warn("The tests' database {$database->database} ".($tables === null ? "doesn't exist" : 'is empty')." — load it once, while no other checkout is testing: {$load}");
        }

        $this->summary['tests'] = "{$database->database}".($tables ? " ({$tables} tables)" : '').' — shared by every checkout: no migrate:fresh, no --parallel, and '.($snapshot ? 'snapshot:load' : 'a bare migrate').' only while nothing else is testing';
    }

    private function printSummary(): void
    {
        $this->console->line();

        foreach ($this->summary as $label => $value) {
            $this->console->line(sprintf('  <fg=gray>%-9s</>%s', $label, $value));
        }
    }

    /**
     * @param  list<string>  $paths
     */
    private static function listed(array $paths): string
    {
        return implode(', ', array_slice($paths, 0, 8)).(count($paths) > 8 ? ' …' : '');
    }
}
