# Contributing to WP Models

Contributions are welcome — bug reports, bug fixes, new features, and documentation improvements. For significant changes, please open an issue first to discuss the approach before investing time in a pull request.

---

## Setting Up Locally

A Docker environment is included so you do not need PHP, Composer, or Node installed locally. If you do have PHP 8.1+ installed, you can also work without Docker.

### With Docker (recommended)

```bash
git clone https://github.com/wptechnix/wp-models.git
cd wp-models

# Build the image (first time only)
docker compose -f docker/docker-compose.yml build

# Install dependencies
./bin/composer install
./bin/npm install        # installs Husky git hooks
```

All `bin/` scripts are thin wrappers that run commands inside the Docker container:

```bash
./bin/composer <args>
./bin/php <args>
./bin/phpunit <args>
./bin/phpcs <args>
./bin/npm <args>
```

### Without Docker

If you have PHP 8.1+, Composer, and Node installed locally:

```bash
git clone https://github.com/wptechnix/wp-models.git
cd wp-models

composer install
npm install   # installs Husky git hooks
```

Then use `composer` and `vendor/bin/phpunit` directly instead of the `bin/` wrappers.

---

## Running Tests

```bash
composer test            # PHPUnit with testdox output
composer test:fast       # stop on first failure
composer test:coverage   # HTML report → build/coverage/ + Clover XML → build/logs/clover.xml
composer test:coverage-ci  # Clover XML + text summary only (no HTML) — what CI runs
```

The `build/` directory is gitignored. HTML reports are for local inspection only; CI generates only the Clover XML.

All tests must pass before a PR can be merged.

---

## Linting and Static Analysis

```bash
composer lint            # runs phpcbf (auto-fix), then phpcs, then phpstan
composer fix:phpcbf      # auto-fix coding style only
composer lint:phpcs      # coding-style check without auto-fix
composer lint:phpstan    # PHPStan level 8 static analysis
```

Configuration files:
- **`phpcs.xml.dist`** — extends the `WPTechnix` ruleset from `wptechnix/coding-standards`
- **`phpstan.neon.dist`** — level 8, `phpstan-strict-rules`, `szepeviktor/phpstan-wordpress`

Run `composer lint` before pushing. The pre-commit hook runs it automatically if you installed Husky.

---

## Git Hooks

`npm install` (or `./bin/npm install`) sets up two Husky hooks:

| Hook | Runs |
|------|------|
| `pre-commit` | `composer lint` |
| `commit-msg` | `commitlint` — validates the commit message format |

---

## Commit Message Convention

This project follows [Conventional Commits](https://www.conventionalcommits.org/):

```
<type>(<optional scope>): <short description>

[optional body]

[optional footer — e.g. Closes #42]
```

**Allowed types:**
`feat` · `fix` · `docs` · `style` · `refactor` · `perf` · `test` · `build` · `ci` · `chore` · `revert`

**Rules:**
- Header must not exceed 100 characters
- Use the imperative mood: "add support for X" not "adds support for X"
- Reference issues in the footer: `Closes #42`

**Examples:**

```
feat(entity): add support for decimal precision cast
fix(model): prevent double cache clear on delete
docs: add caching architecture guide
test(clause-builder): cover BETWEEN operator with floats
refactor(model): extract query builder into separate method
```

---

## Pull Request Process

1. Fork the repository and create a branch from `main`
2. Write or update tests for any behaviour you add or change
3. Ensure `composer lint` and `composer test` both pass
4. Open a PR against `main` with a clear description of what changes and why
5. All CI checks must pass before a PR can be merged

---

## CI Pipeline

The `ci.yml` workflow runs on every push and pull request:

| Check | Details |
|-------|---------|
| PHP matrix | 8.1, 8.2, 8.3 — required; 8.4 — experimental (allowed to fail) |
| Coding style | `phpcs` |
| Static analysis | `phpstan` with result cache |
| Tests | `phpunit --testdox` |
| Coverage | Uploaded as a workflow artifact on pushes to `main` |
| Commitlint | Validates commit messages on pull requests |

---

## Release Process

Releases are fully automated via [release-please](https://github.com/googleapis/release-please). You do not need to manage version numbers or changelogs manually:

1. Merging commits to `main` triggers release-please to open or update a release PR
2. The release PR bumps the version in `composer.json` and updates `CHANGELOG.md`
3. Merging the release PR creates a Git tag (e.g. `v1.2.0`)
4. Packagist picks up the new tag automatically via the configured webhook
