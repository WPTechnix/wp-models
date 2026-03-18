# Query Conditions

All model methods that accept a `$conditions` parameter — `findWhere`, `findOneWhere`, `paginate`, `countWhere`, `updateWhere`, `deleteWhere`, and others — use the same array-based format described on this page.

Conditions are plain PHP arrays: easy to build conditionally, pass around, log, and test. There is no fluent query-builder object to chain.

---

## Security First

Before any SQL is built, every column name is validated against the model's `$queryableColumns` allow-list. If a column is not on the list, an `InvalidArgumentException` is thrown immediately — regardless of where the column name came from. This makes column injection structurally impossible.

```php
// Throws: Column not queryable: secret_token
$products->findWhere([['column' => 'secret_token', 'value' => 'x']]);
```

All values are passed through `wpdb::prepare()` placeholders. They are never interpolated into SQL strings.

---

## Single Condition

A condition is an associative array with two required keys:

- `column` — the column to compare (must be in `$queryableColumns`)
- `value` — the value to compare against

```php
['column' => 'status', 'value' => 'published']
```

The comparison operator defaults to `=`. Add an optional `operator` key to use a different one:

```php
['column' => 'price', 'operator' => '>=', 'value' => 10.00]
```

---

## Supported Operators

| Operator | Description |
|----------|-------------|
| `=` | Equality. Default — `operator` key can be omitted |
| `!=` / `<>` | Inequality |
| `>` / `<` / `>=` / `<=` | Numeric and date comparison |
| `LIKE` | Pattern match — use `%` as wildcard |
| `NOT LIKE` | Inverse pattern match |
| `IN` | Value is in a list — pass an array as `value` |
| `NOT IN` | Value is not in a list — pass an array as `value` |
| `BETWEEN` | Value falls within a range — pass exactly two values as `value` |
| `NOT BETWEEN` | Value falls outside a range — pass exactly two values as `value` |

Operators are case-insensitive: `like`, `Like`, and `LIKE` are all accepted.

---

## NULL Checks

Pass `null` as the value to generate `IS NULL` or `IS NOT NULL`:

```php
// WHERE deleted_at IS NULL
['column' => 'deleted_at', 'value' => null]

// WHERE deleted_at IS NOT NULL
['column' => 'deleted_at', 'operator' => '!=', 'value' => null]
```

---

## Range and List Examples

```php
// BETWEEN — exactly two values required
['column' => 'price', 'operator' => 'BETWEEN', 'value' => [10, 50]]

// IN
['column' => 'status', 'operator' => 'IN', 'value' => ['pending', 'processing']]

// NOT IN
['column' => 'id', 'operator' => 'NOT IN', 'value' => [1, 2, 3]]

// LIKE
['column' => 'name', 'operator' => 'LIKE', 'value' => '%widget%']
```

---

## Multiple Conditions (AND)

Pass a flat list of condition arrays. They are joined with `AND` by default:

```php
$products->findWhere([
    ['column' => 'is_active', 'value' => true],
    ['column' => 'price',     'operator' => '<=', 'value' => '50.00'],
    ['column' => 'status',    'value' => 'published'],
]);
// WHERE `is_active` = 1 AND `price` <= 50.00 AND `status` = 'published'
```

---

## OR Groups

Add `'relation' => 'OR'` alongside your conditions to join them with `OR` instead:

```php
$products->findWhere([
    'relation' => 'OR',
    ['column' => 'status', 'value' => 'featured'],
    ['column' => 'status', 'value' => 'on_sale'],
]);
// WHERE (`status` = 'featured' OR `status` = 'on_sale')
```

---

## Mixing AND and OR

Nest an OR group inside a flat AND list to combine both:

```php
$products->findWhere([
    ['column' => 'is_active', 'value' => true],
    [
        'relation' => 'OR',
        ['column' => 'status', 'value' => 'featured'],
        ['column' => 'price',  'operator' => '<', 'value' => '10.00'],
    ],
]);
// WHERE `is_active` = 1 AND (`status` = 'featured' OR `price` < 10.00)
```

Groups can be nested to any depth.

---

## Building Conditions Dynamically

Since conditions are plain arrays, building them from request parameters or settings is straightforward:

```php
$conditions = [];

if (! empty($_GET['status'])) {
    $conditions[] = [
        'column' => 'status',
        'value'  => sanitize_text_field($_GET['status']),
    ];
}

if (! empty($_GET['min_price'])) {
    $conditions[] = [
        'column'   => 'price',
        'operator' => '>=',
        'value'    => (float) $_GET['min_price'],
    ];
}

$results = $products->findWhere($conditions);
```

---

## ORDER BY

The `orderBy` parameter is a `['column' => 'direction']` map. Only columns in `$queryableColumns` may be used. Direction is `ASC` or `DESC` (any unrecognised value is treated as `DESC`).

```php
$products->findWhere(
    conditions: [['column' => 'is_active', 'value' => true]],
    orderBy: [
        'price'      => 'ASC',
        'created_at' => 'DESC',
    ],
);
```

When `orderBy` is omitted, results are sorted by `{primaryKey} DESC`.

---

## Automatic Corrections

The library silently normalises the most common mistakes:

| Input | Corrected to |
|-------|-------------|
| `operator: '='` with an array value | `IN` |
| `operator: '!='` with an array value | `NOT IN` |
| `operator: '='` with `null` as value | `IS NULL` |
| `operator: '!='` with `null` as value | `IS NOT NULL` |
