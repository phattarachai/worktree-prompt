# Contributing

Thanks for taking the time. Bug reports, fixes and small focused features are all welcome.

## Getting set up

```bash
git clone https://github.com/phattarachai/worktree-prompt.git
cd worktree-prompt
composer install
vendor/bin/pest
```

The tests run real git and Composer against a small Laravel-shaped fixture (an `artisan` stand-in and an `App\`
autoload) cloned into the system temp directory, with a faked terminal for the prompts. No Laravel install, no
database server and no network are needed; npm and Herd are stood in for.

## Before opening a pull request

- **Add or update a test.** A change without a test that would have caught the bug is hard to keep working.
- **Run the checks:** `vendor/bin/pest`, `vendor/bin/pint`, `vendor/bin/phpstan analyse`.
- **Keep the diff to one thing.** Unrelated refactors and reformatting make review slow; open a second PR for them.
- **Write the PR title as a changelog line** — `fix: ...`, `feat: ...`, `docs: ...`. Release notes are generated from
  those titles, so the title is what users end up reading.

CI runs the suite on every supported PHP version on Linux and macOS, with both the lowest and the latest allowed
dependencies.
A red `prefer-lowest` job usually means a version constraint in `composer.json` needs raising.

## Reporting a bug

Open an issue with the package version, git version and OS, PHP version, and the smallest reproduction you can manage.
Security problems go through [SECURITY.md](SECURITY.md) instead — please do not open a public issue for those.
