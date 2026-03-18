# Model Reference

A **model** represents a single database table and provides all operations needed to work with its rows. You always work with a singleton instance, so internal caches are shared throughout the request.

---

## Defining a Model

```php
<?php

use WPTechnix\WPModels\AbstractModel;

class ProductModel extends AbstractModel
{
    /** Table name without the WordPress prefix */
    protected string $table = 'products';

    /** The entity class rows are hydrated into */
    protected string $entityClass = ProductEntity::class;

    /** Primary key column name */
    protected string $primaryKey = 'id';

    /**
     * Columns allowed in WHERE and ORDER BY clauses.
     * This is a security allow-list. Any column not listed here will
     * throw an InvalidArgumentException if referenced in a query.
     */
    protected array $queryableColumns = [
        'id', 'name', 'status', 'is_active', 'price', 'created_at',
    ];
}
```

---

## Getting an Instance

Always use `::instance()` — constructing a model directly with `new` is not supported.

```php
$products = ProductModel::instance();
```

---

## Creating Rows

### `create(array $data): int|false`

Inserts a single row. Returns the new primary key on success, `false` on failure.

```php
$id = $products->create([
    'name'      => 'Wireless Mouse',
    'price'     => '29.99',
    'is_active' => true,
    'status'    => 'draft',
]);

if ($id === false) {
    $error = $products->getLastError();
}
```

### `createMany(array $rows): int`

Inserts multiple rows in a single SQL statement. Returns the number of rows inserted. More efficient than calling `create()` in a loop for bulk data.

```php
$count = $products->createMany([
    ['name' => 'USB Hub',   'price' => '19.99', 'status' => 'draft'],
    ['name' => 'Webcam',    'price' => '49.99', 'status' => 'draft'],
    ['name' => 'Desk Lamp', 'price' => '34.99', 'status' => 'draft'],
]);
```

---

## Reading a Single Row

### `find(int $id): ?TEntity`

Fetches one entity by primary key. Returns `null` if the row does not exist.

```php
$product = $products->find(42);

if ($product === null) {
    wp_die('Product not found.');
}
```

### `exists(int $id): bool`

Returns `true` if a row with the given primary key exists, without loading the full row.

```php
if (! $products->exists(42)) {
    wp_die('Product not found.');
}
```

---

## Reading Multiple Rows

### `findMany(array $ids): array`

Fetches multiple entities by primary key in a single query. Returns `array<int, TEntity>` keyed by primary key. IDs not found in the database are silently omitted.

```php
$items = $products->findMany([1, 2, 3]);

foreach ($items as $id => $product) {
    echo "{$id}: {$product->name}";
}
```

### `findWhere(array $conditions, ?array $orderBy = null, ?int $limit = null): array`

Returns all rows matching the given conditions. See [Query Conditions](clause-builder.md) for the full syntax.

```php
// All active products
$active = $products->findWhere([
    ['column' => 'is_active', 'value' => true],
]);

// With ordering and a row limit
$cheap = $products->findWhere(
    conditions: [
        ['column' => 'is_active', 'value' => true],
        ['column' => 'price', 'operator' => '<=', 'value' => '20.00'],
    ],
    orderBy: ['price' => 'ASC'],
    limit: 10,
);
```

### `findOneWhere(array $conditions, ?array $orderBy = null): ?TEntity`

Like `findWhere` but returns only the first match, or `null`.

```php
$featured = $products->findOneWhere(
    [['column' => 'status', 'value' => 'featured']],
    ['created_at' => 'DESC'],
);
```

### `findBy(string $column, mixed $value): array`

Shorthand for a single equality condition across all matching rows.

```php
$drafts = $products->findBy('status', 'draft');
```

### `findOneBy(string $column, mixed $value): ?TEntity`

Shorthand for a single equality condition, first result only.

```php
$product = $products->findOneBy('status', 'featured');
```

---

## Upsert Helpers

### `firstOrCreate(array $attributes, array $values = []): ?TEntity`

Finds the first row matching `$attributes`. If none exists, creates a new row using `array_merge($attributes, $values)`.

```php
$product = $products->firstOrCreate(
    ['name' => 'New Gadget', 'status' => 'draft'],  // look for this
    ['price' => '0.00'],                            // add this on create
);
```

### `updateOrCreate(array $attributes, array $values): ?TEntity`

Finds the first row matching `$attributes` and updates it with `$values`. If no row exists, creates one with `array_merge($attributes, $values)`.

```php
$product = $products->updateOrCreate(
    ['name' => 'New Gadget'],                         // find by
    ['price' => '39.99', 'status' => 'published'],   // apply these
);
```

---

## Updating Rows

### `update(int $id, array $data): bool`

Updates specific columns on a single row by primary key. Only the columns you pass are affected.

```php
$products->update(42, [
    'price'     => '24.99',
    'is_active' => true,
]);
```

### `updateWhere(array $data, array $conditions): int`

Updates all rows matching the conditions. Returns the number of rows affected.

> **Cache note:** Invalidates the query cache but does not clear individual entity caches. See [Caching — Bulk Operations](caching.md#bulk-operation-cache-caveat) if you need full cache consistency after a bulk update.

```php
$affected = $products->updateWhere(
    ['status' => 'archived'],
    [['column' => 'is_active', 'value' => false]],
);
```

### `increment(int $id, string $column, int $amount = 1): bool`

Increments a numeric column atomically. Safe against concurrent updates.

```php
$products->increment(42, 'view_count');
$products->increment(42, 'stock', 10);
```

### `decrement(int $id, string $column, int $amount = 1): bool`

Decrements a numeric column atomically.

```php
$products->decrement(42, 'stock');
$products->decrement(42, 'stock', 3);
```

---

## Deleting Rows

### `delete(int $id): bool`

Deletes a single row by primary key.

```php
$products->delete(42);
```

### `deleteWhere(array $conditions): int`

Deletes all rows matching the conditions. Returns the number of deleted rows.

> **Cache note:** Same behaviour as `updateWhere` — query cache is cleared, individual entity caches are not.

```php
$deleted = $products->deleteWhere([
    ['column' => 'status', 'value' => 'archived'],
]);
```

---

## Counting and Projection

### `countWhere(array $conditions = []): int`

Counts rows matching the conditions. Omit conditions or pass `[]` to count all rows in the table.

```php
$total  = $products->countWhere();
$active = $products->countWhere([['column' => 'is_active', 'value' => true]]);
```

### `pluck(string $column, array $conditions = [], ?string $keyBy = null): array`

Returns a flat array of a single column's values, optionally indexed by another column.

```php
// Flat list of all product names
$names = $products->pluck('name');
// ['Wireless Mouse', 'USB Hub', ...]

// Names indexed by primary key
$nameById = $products->pluck('name', [], 'id');
// [42 => 'Wireless Mouse', 43 => 'USB Hub', ...]

// Names of active products only
$activeNames = $products->pluck('name', [
    ['column' => 'is_active', 'value' => true],
]);
```

---

## Pagination

### `paginate(int $page, int $perPage, array $conditions = [], ?array $orderBy = null): PaginatedResult`

Returns a `PaginatedResult` containing the current page's entities and pagination metadata. See [PaginatedResult Reference](paginated-result.md) for everything you can do with the result.

```php
$page = $products->paginate(
    page: absint($_GET['paged'] ?? 1),
    perPage: 20,
    conditions: [['column' => 'is_active', 'value' => true]],
    orderBy: ['created_at' => 'DESC'],
);

foreach ($page->items as $product) {
    echo $product->name;
}

echo "Page {$page->page} of {$page->totalPages}";
echo "Showing {$page->getFromNumber()}–{$page->getToNumber()} of {$page->total}";
```

---

## Large Datasets

For tables with many rows, `findWhere` will load all results into memory at once. Use chunking instead.

### `chunk(array $conditions, callable $callback, int $chunkSize = 1000): void`

Processes rows in batches, calling `$callback` with each batch. Return `false` from the callback to stop early.

```php
$products->chunk(
    conditions: [['column' => 'status', 'value' => 'draft']],
    callback: function (array $batch): void {
        foreach ($batch as $product) {
            // process product
        }
    },
    chunkSize: 500,
);
```

### `chunkGenerator(array $conditions): Generator`

Returns a `Generator` that yields one entity per iteration, fetching rows from the database in batches internally.

```php
foreach ($products->chunkGenerator([]) as $product) {
    process($product);
}
```

---

## Error Handling

When a write operation fails, the model stores the last database error:

```php
$id = $products->create(['name' => 'Gadget']);

if ($id === false) {
    $error = $products->getLastError();  // string|null
    error_log("Insert failed: {$error}");
}
```

---

## Utilities

```php
$products->getTableName();  // 'wp_products' — full name with site prefix
$products->getPrimaryKey(); // 'id'
```
