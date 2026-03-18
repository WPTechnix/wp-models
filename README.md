# WP Models

[![CI](https://github.com/wptechnix/wp-models/actions/workflows/ci.yml/badge.svg)](https://github.com/wptechnix/wp-models/actions/workflows/ci.yml)
[![Latest Version](https://img.shields.io/packagist/v/wptechnix/wp-models.svg)](https://packagist.org/packages/wptechnix/wp-models)
[![PHP Version](https://img.shields.io/packagist/php-v/wptechnix/wp-models.svg)](https://packagist.org/packages/wptechnix/wp-models)
[![License](https://img.shields.io/github/license/wptechnix/wp-models.svg)](LICENSE)

**Type-safe Active Record ORM for WordPress plugin development.**

WP Models replaces scattered `$wpdb` queries with a clean data-access layer. Database rows become typed PHP objects with automatic casting, UTC-aware datetime handling, safe SQL compilation, built-in object caching, and pagination — all without pulling in a full framework.

---

## Features

- **Typed entities** — declare column types once (`int`, `decimal`, `bool`, `datetime`, `json`, `enum:ClassName`); the library casts on read and serialises on write, no manual conversion needed
- **Full query API** — `find`, `findWhere`, `paginate`, `create`, `update`, `delete`, `firstOrCreate`, `updateOrCreate`, bulk variants, `chunk`, `pluck`, and more
- **Safe SQL by design** — conditions use a column allow-list and `wpdb::prepare()` placeholders; SQL injection is structurally prevented, not just filtered
- **Automatic caching** — two-level WordPress object cache (query result IDs + entity rows); reads hit cache first, writes stay consistent automatically
- **Pagination** — `paginate()` returns an immutable result object with navigation helpers, page-number generation for UI controls, and `JsonSerializable` output for REST API responses
- **UTC datetime handling** — dates stored as UTC, returned in the WordPress site timezone, written back as UTC — no manual timezone conversion required
- **PHP 8.1 Backed Enums** — first-class enum casting in entity definitions
- **Dirty tracking** — know exactly which attributes changed before calling `save()`

---

## Requirements

| Requirement | Version / Notes |
|-------------|-----------------|
| PHP | `^8.1` |
| WordPress | Loaded by your plugin — provides `$wpdb`, `ABSPATH`, and timezone functions |

---

## Installation

```bash
composer require wptechnix/wp-models
```

Load Composer's autoloader in your plugin file if you haven't already:

```php
// my-plugin/my-plugin.php
require_once __DIR__ . '/vendor/autoload.php';
```

---

## Quick Start

### Define an Entity and a Model

```php
use WPTechnix\WPModels\AbstractEntity;
use WPTechnix\WPModels\AbstractModel;

// One entity class represents one database row
class OrderEntity extends AbstractEntity
{
    protected static string $primaryKey = 'id';

    protected static array $casts = [
        'id'         => 'int',
        'user_id'    => 'int',
        'total'      => 'decimal',   // stored and returned as string — safe for money
        'status'     => 'string',
        'is_paid'    => 'bool',
        'meta'       => 'json',      // auto-encoded/decoded
        'created_at' => 'datetime',  // stored UTC, returned in WP timezone
    ];
}

// One model class manages one database table
class OrderModel extends AbstractModel
{
    protected string $table       = 'orders';           // without WP prefix
    protected string $entityClass = OrderEntity::class;
    protected string $primaryKey  = 'id';

    // Security allow-list: only these columns may appear in WHERE / ORDER BY
    protected array $queryableColumns = [
        'id', 'user_id', 'status', 'is_paid', 'total', 'created_at',
    ];
}
```

### Create, Read, Update, Delete

```php
$orders = OrderModel::instance();

// Create
$id = $orders->create([
    'user_id' => get_current_user_id(),
    'total'   => '49.99',
    'status'  => 'pending',
    'meta'    => ['source' => 'checkout'],
]);

// Read — properties are typed, no casting needed
$order = $orders->find($id);
echo $order->total;                         // string '49.99'
echo $order->is_paid;                       // bool false
echo $order->created_at->format('d M Y');   // DateTimeImmutable in WP timezone
echo $order->meta['source'];               // 'checkout'

// Update
$orders->update($id, ['status' => 'complete', 'is_paid' => true]);

// Delete
$orders->delete($id);
```

### Query and Paginate

```php
// Filter with conditions
$pending = $orders->findWhere([
    ['column' => 'status',  'value' => 'pending'],
    ['column' => 'is_paid', 'value' => false],
], orderBy: ['created_at' => 'DESC']);

// Paginate
$page = $orders->paginate(
    page: absint($_GET['paged'] ?? 1),
    perPage: 20,
    conditions: [['column' => 'user_id', 'value' => get_current_user_id()]],
);

foreach ($page->items as $order) {
    echo $order->total;
}

echo "Showing {$page->getFromNumber()}–{$page->getToNumber()} of {$page->total}";
```

---

## Documentation

| Guide | Description |
|-------|-------------|
| [Getting Started](docs/getting-started.md) | Installation, table setup, first entity and model, full usage walkthrough |
| [Entity Reference](docs/entity.md) | Cast types, datetime and timezone handling, enum support, dirty tracking, save and delete |
| [Model Reference](docs/model.md) | Complete query API — CRUD, bulk operations, pagination, chunking, aggregates |
| [Query Conditions](docs/clause-builder.md) | Conditions format, all operators, OR groups, building conditions dynamically |
| [PaginatedResult Reference](docs/paginated-result.md) | Navigation helpers, page number generation, REST API serialisation |
| [Caching](docs/caching.md) | How caching works, what clears it, and how to handle edge cases |

---

## Changelog

See [CHANGELOG.md](CHANGELOG.md).

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md).

## License

MIT © [WPTechnix](https://wptechnix.com) — see [LICENSE](LICENSE).
