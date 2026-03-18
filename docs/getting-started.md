# Getting Started

This guide walks you through installing WP Models and using it in a WordPress plugin for the first time. By the end you will have a working entity, model, and basic CRUD operations.

## Prerequisites

- PHP `^8.1`
- A WordPress plugin (or theme) where WordPress is fully loaded before your code runs — the library requires `$wpdb`, `ABSPATH`, and `get_option('timezone_string')` to be available at runtime

> **Important:** WP Models does not create database tables. You are responsible for creating your tables (typically in `register_activation_hook`) before using a model against them.

---

## Installation

Run this in your plugin directory, where your `composer.json` lives:

```bash
composer require wptechnix/wp-models
```

Then load Composer's autoloader near the top of your main plugin file:

```php
// my-plugin/my-plugin.php
require_once __DIR__ . '/vendor/autoload.php';
```

---

## Core Concepts

The library is built around two classes you extend for each database table:

| Class | Represents |
|-------|-----------|
| `AbstractEntity` | A single database row — typed properties, casting, dirty tracking, `save()`, `delete()` |
| `AbstractModel` | A database table — `find()`, `create()`, `findWhere()`, `paginate()`, and the full query API |

Your model returns instances of your entity. The two are always paired.

---

## Step 1 — Create a Table

WP Models has no opinion on your schema. Use WordPress's standard `dbDelta` to register the table on plugin activation:

```php
register_activation_hook(__FILE__, function () {
    global $wpdb;
    $table   = $wpdb->prefix . 'orders';
    $charset = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE {$table} (
        id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id     BIGINT UNSIGNED NOT NULL,
        total       DECIMAL(10,2)   NOT NULL DEFAULT '0.00',
        status      VARCHAR(50)     NOT NULL DEFAULT 'pending',
        is_paid     TINYINT(1)      NOT NULL DEFAULT 0,
        note        TEXT,
        meta        LONGTEXT,
        created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id)
    ) {$charset};";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);
});
```

---

## Step 2 — Define an Entity

Create one entity class per table. Declare every column you want to work with in the `$casts` array, along with its type:

```php
<?php

use WPTechnix\WPModels\AbstractEntity;

class OrderEntity extends AbstractEntity
{
    protected static string $primaryKey = 'id';

    protected static array $casts = [
        'id'         => 'int',
        'user_id'    => 'int',
        'total'      => 'decimal',   // returned as string — preserves precision
        'status'     => 'string',
        'is_paid'    => 'bool',
        'note'       => 'string',
        'meta'       => 'json',      // automatically encoded/decoded
        'created_at' => 'datetime',  // stored UTC, returned in WP timezone
    ];
}
```

Every column must be listed in `$casts`. Accessing an undeclared column throws an exception. See the [Entity Reference](entity.md) for all available cast types.

---

## Step 3 — Define a Model

Create one model class per table. It needs to know the table name, which entity class to produce, and which columns may be used in queries:

```php
<?php

use WPTechnix\WPModels\AbstractModel;

class OrderModel extends AbstractModel
{
    protected string $table       = 'orders';           // without WP prefix
    protected string $entityClass = OrderEntity::class;
    protected string $primaryKey  = 'id';

    // Security allow-list: only these columns may be used in WHERE / ORDER BY.
    // Referencing any other column throws InvalidArgumentException.
    protected array $queryableColumns = [
        'id', 'user_id', 'status', 'is_paid', 'total', 'created_at',
    ];
}
```

---

## Step 4 — Create and Read Rows

Get the model singleton and start working:

```php
$orders = OrderModel::instance();

// Insert a row — returns the new primary key, or false on failure
$id = $orders->create([
    'user_id' => get_current_user_id(),
    'total'   => '49.99',
    'status'  => 'pending',
    'meta'    => ['source' => 'checkout'],
]);

// Fetch a single row by primary key
$order = $orders->find($id);   // OrderEntity|null

if ($order !== null) {
    echo $order->total;                        // '49.99'
    echo $order->created_at->format('d M Y'); // DateTimeImmutable in WP timezone
    echo $order->meta['source'];              // 'checkout'
}
```

---

## Step 5 — Update and Delete

```php
// Update specific columns on one row
$orders->update($id, ['status' => 'complete', 'is_paid' => true]);

// Or update via the entity itself
$order->status = 'complete';
$order->is_paid = true;
$order->save();

// Delete by primary key
$orders->delete($id);

// Or delete via the entity
$order->delete();
```

---

## Step 6 — Query and Paginate

```php
// All pending orders for the current user, newest first
$pending = $orders->findWhere(
    conditions: [
        ['column' => 'user_id', 'value' => get_current_user_id()],
        ['column' => 'status',  'value' => 'pending'],
    ],
    orderBy: ['created_at' => 'DESC'],
);

// Paginate results
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

## Next Steps

| Topic | Guide |
|-------|-------|
| All cast types, datetime, enum support, dirty tracking | [Entity Reference](entity.md) |
| Full query API — bulk operations, chunking, aggregates | [Model Reference](model.md) |
| Conditions syntax — operators, OR groups, dynamic conditions | [Query Conditions](clause-builder.md) |
| Working with paginated results and pagination UI | [PaginatedResult Reference](paginated-result.md) |
| How caching works and when to act on it | [Caching](caching.md) |
