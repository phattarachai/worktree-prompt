# Worktree Prompt

[![Latest Version on Packagist](https://img.shields.io/packagist/v/phattarachai/worktree-prompt.svg?style=flat-square)](https://packagist.org/packages/phattarachai/worktree-prompt)
[![Tests](https://img.shields.io/github/actions/workflow/status/phattarachai/worktree-prompt/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/phattarachai/worktree-prompt/actions/workflows/run-tests.yml?query=branch%3Amain)
[![Code Style](https://img.shields.io/github/actions/workflow/status/phattarachai/worktree-prompt/fix-php-code-style-issues.yml?branch=main&label=code%20style&style=flat-square)](https://github.com/phattarachai/worktree-prompt/actions/workflows/fix-php-code-style-issues.yml?query=branch%3Amain)
[![PHP Version](https://img.shields.io/packagist/dependency-v/phattarachai/worktree-prompt/php?style=flat-square&label=php&logo=php&logoColor=white)](https://packagist.org/packages/phattarachai/worktree-prompt)
![Built on Laravel Prompts](https://img.shields.io/badge/built%20on-Laravel%20Prompts-FF2D20?style=flat-square&logo=laravel&logoColor=white)
[![Total Downloads](https://img.shields.io/packagist/dt/phattarachai/worktree-prompt.svg?style=flat-square)](https://packagist.org/packages/phattarachai/worktree-prompt)

A git worktree of a Laravel project, ready to run, in one command. `git worktree add` gives you the branch's
files and nothing else: no `vendor`, no `.env`, no `storage` folders, no Vite build, and the same database as
the main checkout. `lwt add` does the rest, and `lwt remove` takes all of it down again.

It is a global command built on [Laravel Prompts](https://github.com/laravel/prompts), so it works in every
Laravel project on your machine without being added to any of them, and it runs before the worktree has a
`vendor` (which an artisan command can't).

```
$ lwt add fix/login-redirect -n --db=copy
  → git worktree add --no-track -b fix/login-redirect ~/www/shop-login-redirect origin/main
  ✓ .env — copied from the main checkout
  → cp -cR ~/www/shop/vendor ~/www/shop-login-redirect/vendor
  ✓ vendor — a real copy of the main checkout's (composer.lock is the same), never a symlink
  ✓ APP_URL — http://shop-login-redirect.test
  ✓ database — created pgsql shop_db_login_redirect on 127.0.0.1:5432
  → pg_dump --no-owner --no-privileges … shop_db | psql --quiet --set ON_ERROR_STOP=1 … --dbname shop_db_login_redirect
  ✓ public/build — copied from the main checkout
  → php artisan migrate --force --no-interaction
  ✓ Laravel Framework 13.33.0 — App\ loads from this worktree

  url      http://shop-login-redirect.test
  database pgsql shop_db_login_redirect on 127.0.0.1:5432 — a copy of shop_db
  tests    shop_testing (40 tables) — shared by every checkout: no migrate:fresh, no --parallel, and snapshot:load only while nothing else is testing

  cd ~/www/shop-login-redirect
```

- **`vendor` is a real copy, never a symlink.** Composer's autoloader resolves `__DIR__` through a symlink, so
  a symlinked `vendor` loads `App\…` from the *main* checkout: you edit one branch and run another, with no
  error to tell you. When `composer.lock` matches, `vendor` is copied as a copy-on-write clone (`cp -c` on APFS,
  `--reflink` on btrfs/XFS): a 230 MB `vendor` takes about two seconds and no extra disk. When the lock differs,
  it runs `composer install`. After the setup it checks that `App\` loads from the worktree.
- **A database of its own when the branch needs one.** `--db=copy` creates `<db>_<branch>` and copies the main
  database into it (`pg_dump | psql`, `mysqldump | mysql`, or the SQLite file), `--db=empty` migrates a new
  one. Either way the branch's migrations then run on *that* database, never on the shared one. When the branch
  already changes `database/migrations`, the prompt suggests a copy.
- **Nothing tracked is changed.** It never writes over a tracked file (a committed `.env.testing` stays as it
  is). Composer scripts that republish `public/` assets, or an install that rewrites a lockfile, are put back with
  `git restore` at the end, and new untracked files are listed.
- **Everything else git doesn't carry:** the gitignored `storage/` and `bootstrap/cache` folders (without
  `storage/framework/views` every Blade render fails with *Please provide a valid cache path*), `public/build`
  and `bootstrap/ssr` (views that read the Vite manifest), the `public/storage` link, untracked `.env.*` files,
  and, when you ask for Node, `node_modules` (copied when the lockfile matches, a clean `npm ci` when it
  doesn't) plus a project-local `.cache/puppeteer`.
- **A site of its own.** The default folder sits beside the main checkout (`../shop-login-redirect`), so a
  parked folder serves it as `shop-login-redirect.test`. `APP_URL` moves to it, with `SESSION_DOMAIN`,
  `SANCTUM_STATEFUL_DOMAINS` and the `*_URL` keys on the same host.
- **Cleans up all of it.** `lwt remove` refuses a worktree with uncommitted or untracked files, drops the database
  and unlinks the site it made, removes the worktree, offers to delete the branch (only a merged one without
  asking twice), runs `git worktree prune`, and removes an emptied parent such as `.claude/worktrees/`.
- **Shows the commands it runs**, so you can see, and learn, exactly what a worktree needs.

## Install

```bash
composer global require phattarachai/worktree-prompt
```

Then put Composer's global bin on your `PATH`, or alias it:

```bash
alias lwt="$HOME/.composer/vendor/bin/lwt"
```

Needs PHP 8.3+, git, and `pg_dump`/`psql` or `mysqldump`/`mysql` on your `PATH` for `--db=copy`.

## Usage

| Command | Does |
|---|---|
| `lwt add [branch]` | Fetch, pick or create the branch, pick the folder, `git worktree add`, then set it up |
| `lwt setup [path]` | Set up a worktree that already exists (default: the one you are in) |
| `lwt remove [path\|branch]` | Remove a clean worktree, its database and its site; offer to delete the branch |
| `lwt list` | Every worktree, whether it is set up, and what lwt made for it |

With a terminal, `lwt add` asks for whatever you didn't pass. A new branch is made from `origin/HEAD` (usually
`origin/main`) with `--no-track`, so its first push sets the upstream to its own name. A branch that exists only
on the remote is checked out tracking it.

`lwt setup` is for worktrees something else made: plain `git worktree add`, git-prompt's Worktrees tab, or an
AI agent's worktree in `.claude/worktrees/`. It is safe to run again; whatever is already in place is kept.

### Options

| Option | |
|---|---|
| `--db=shared\|empty\|copy` | The main checkout's database (default), a new empty one (migrated), or a copy of it |
| `--seed` | Seed the new empty database |
| `--db-admin=<user>` | Create the database as `root` or `postgres` when the app's own user may not; it is then granted to (MySQL) or owned by (Postgres) the app's user. A password comes from `LWT_DB_ADMIN_PASSWORD` |
| `--url=<url>` | `APP_URL` for the worktree |
| `--npm`, `--node=auto\|copy\|install` | `node_modules`: copy when the lockfile matches, else a clean install |
| `--build` | `npm run build` (or pnpm, yarn, bun), implies `--npm` |
| `--vendor=auto\|copy\|install` | Force a copy or a `composer install` |
| `--env=copy\|symlink` | `.env` as a copy (default) or a symlink to the main checkout's; a symlink can't have its own database or URL |
| `--base=<ref>`, `--path=<folder>`, `--no-fetch` | `add`: where to branch from, where to put it, skip `git fetch` |
| `--delete-branch`, `--drop-db` | `remove` without a terminal: what to take down besides the folder |
| `-n`, `--no-interaction` | No prompts, only the defaults and the flags |

A database is only ever created on this machine. When `DB_HOST` is another server, the shared database is kept,
and without a terminal the setup fails, so a script or an agent never continues on the wrong database.

### Gitignored files a project needs in every worktree

List them in `.worktreeinclude` at the project root, one path or glob per line, the same file
[Claude Code](https://code.claude.com/docs/en/worktrees) reads for its own worktrees (lwt reads plain paths and
`*` globs; `**` and `!` lines are skipped):

```
.cache/certs
storage/app/fixtures
```

Only gitignored paths are copied, and nothing that already exists in the worktree is overwritten.

### For scripts and AI agents

```bash
lwt setup -n                      # in a worktree someone else made: real vendor, .env, storage, build output
lwt add feat/export -n --db=copy  # a branch with migrations gets a database of its own
lwt remove feat/export -n --drop-db --delete-branch
```

The exit code is non-zero when anything asked for didn't happen (a database that couldn't be created, a failed
install or migration, or `App\` loading from outside the worktree).

## Tests stay on the shared test database

The app gets a database of its own with `--db`; the tests don't. Projects that pin their test database by name
(a guard test that asserts `config('database.connections.pgsql.database')` is `shop_testing`) keep passing in
every worktree, and the baseline is loaded once for the whole machine. The setup checks that database from
`.env.testing` and `phpunit.xml`, and tells you how to load it when it is missing or empty:

```
  ! The tests' database shop_testing doesn't exist — load it once, while no other checkout is testing: php artisan snapshot:load testing --env=testing --force
```

(`snapshot:load` when the project has `storage/app/snapshots/testing.sql`, from
[spatie/laravel-db-snapshots](https://github.com/spatie/laravel-db-snapshots); `migrate --env=testing`
otherwise.) Because it is shared, every checkout's tests should roll back (`DatabaseTransactions`), and nothing
that rebuilds it — `migrate:fresh`, `php artisan test --parallel`, or a `snapshot:load` — runs while another
worktree's tests might be running. A branch that adds a column applies only that migration to it:
`php artisan migrate --path=database/migrations/<file> --env=testing`.

Redis is shared too: give any test that touches it directly a unique key. Uploaded files under
`storage/app/public` stay in the main checkout.

## Testing

```bash
composer test
```

The suite drives real git and Composer against a small Laravel-shaped project in the system temp directory,
with a faked terminal for the prompts.

## ผู้พัฒนา

พัฒนาและดูแลโดย [บริษัท ภัทรชัย อาร์ทิซาน จำกัด](https://phattarachai.dev) (Phattarachai Artisan)
บริษัทที่ปรึกษาและพัฒนาเว็บ ที่เรียนรู้และแบ่งปันกับชุมชน Laravel
แพ็กเกจนี้แบ่งปันให้ชุมชนนำไปใช้และต่อยอดได้อย่างอิสระ

ดูแพ็กเกจอื่นของเราได้ที่ [phattarachai.dev/open-source](https://phattarachai.dev/open-source)
และติดต่อเราได้ที่ [phattarachai.dev](https://phattarachai.dev/#contact)
