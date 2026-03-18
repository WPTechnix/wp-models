# WP Models

[![CI](https://github.com/wptechnix/wp-models/actions/workflows/ci.yml/badge.svg)](https://github.com/wptechnix/wp-models/actions/workflows/ci.yml)
[![Latest Version](https://img.shields.io/packagist/v/wptechnix/wp-models.svg)](https://packagist.org/packages/wptechnix/wp-models)
[![PHP Version](https://img.shields.io/packagist/php-v/wptechnix/wp-models.svg)](https://packagist.org/packages/wptechnix/wp-models)
[![License](https://img.shields.io/github/license/wptechnix/wp-models.svg)](LICENSE)

Type-safe **Active Record ORM** models and entities for WordPress plugin development.
Provides a clean data-access layer on top of `wpdb` with automatic type casting,
two-level caching, and a safe SQL clause compiler.

---

## Requirements

| Requirement | Version  |
|-------------|----------|
| PHP         | `^8.1`   |
| WordPress   | loaded by your plugin (provides `wpdb`, `ABSPATH`, etc.) |

## Installation

```bash
composer require wptechnix/wp-models
```

## Core Classes

| Class | Purpose |
|---|---|
| `AbstractEntity` | Active Record base — typed property bag, UTC date handling, JSON/Enum casting |
| `AbstractModel`  | Singleton CRUD layer — `find`, `create`, `update`, `delete`, pagination, two-level cache |
| `ClauseBuilder`  | Safe SQL WHERE/ORDER compiler for `wpdb::prepare()` — allow-list, nested AND/OR groups |
| `PaginatedResult`| Immutable value object with pagination metadata and helpers |

## Quick Start

```php
use WPTechnix\WPModels\AbstractEntity;
use WPTechnix\WPModels\AbstractModel;

// 1. Define your entity (one row = one entity)
class OrderEntity extends AbstractEntity
{
    protected static array $casts = [
        'id'         => 'int',
        'total'      => 'decimal',
        'status'     => 'string',
        'created_at' => 'datetime',
        'meta'       => 'json',
    ];
}

// 2. Define your model (one model = one table)
class OrderModel extends AbstractModel
{
    protected string $table = 'orders';
    protected string $entityClass = OrderEntity::class;
    protected string $primaryKey = 'id';
}

// 3. Use it
$model  = OrderModel::instance();
$order  = $model->find(42);          // OrderEntity|null
$orders = $model->findWhere([
    ['column' => 'status', 'value' => 'pending'],
]);

$paginated = $model->paginate(page: 1, perPage: 20);
echo $paginated->total;              // total matching rows
```

## Local Development (Docker)

A pre-configured Docker environment is provided — no local PHP or Node required.

```bash
# Build the image (first time only)
docker compose -f docker/docker-compose.yml build

# Run any command via the thin wrappers in bin/
./bin/composer install
./bin/php --version
./bin/phpunit --testdox
./bin/phpcs
./bin/npm install
```

## Composer Scripts

```bash
composer test            # run PHPUnit (testdox output)
composer test:fast       # stop on first failure
composer test:coverage   # generate HTML + Clover coverage
composer lint            # phpcbf auto-fix → phpcs → phpstan
composer lint:phpcs      # coding-style check only
composer lint:phpstan    # static analysis only
composer fix:phpcbf      # auto-fix coding style
```

## Contributing

1. Fork the repository.
2. Create a branch: `git checkout -b feat/my-feature`
3. Commit using [Conventional Commits](https://www.conventionalcommits.org/).
4. Open a pull request against `main`.

CI will run automatically. All checks must pass before merging.

## Changelog

See [CHANGELOG.md](CHANGELOG.md) — maintained automatically by release-please.

## License

MIT © [WPTechnix](https://wptechnix.com) — see [LICENSE](LICENSE).
