# PaginatedResult Reference

`PaginatedResult` is the immutable value object returned by `AbstractModel::paginate()`. It holds the current page's entities alongside pagination metadata, and provides helpers for navigation UI, page-number generation, and REST API serialisation.

---

## Getting a Result

```php
$result = ProductModel::instance()->paginate(
    page: absint($_GET['paged'] ?? 1),
    perPage: 20,
    conditions: [['column' => 'is_active', 'value' => true]],
    orderBy: ['created_at' => 'DESC'],
);
```

---

## Properties

All properties are `readonly`.

| Property | Type | Description |
|----------|------|-------------|
| `$items` | `array<int, TEntity>` | Entities on this page, keyed by primary key |
| `$total` | `int` | Total rows matching the query across all pages |
| `$page` | `int` | Current page number (1-indexed) |
| `$perPage` | `int` | Items per page |
| `$totalPages` | `int` | Total number of pages |

---

## Iterating Items

```php
// Keyed by primary key
foreach ($result->items as $id => $product) {
    echo "{$id}: {$product->name}";
}

// As a flat list (no ID keys)
foreach ($result->getItemsList() as $product) {
    echo $product->name;
}

// Just the IDs
$ids = $result->getIds();  // [42, 43, 44, ...]
```

---

## Checking the Result

```php
$result->isEmpty();    // true when no items on this page
$result->isNotEmpty(); // true when there is at least one item
$result->count();      // number of items on this page (not the total across all pages)
```

---

## Navigation

```php
$result->hasNextPage();      // bool
$result->hasPreviousPage();  // bool
$result->getNextPage();      // int|null — next page number, or null on the last page
$result->getPreviousPage();  // int|null — previous page number, or null on the first page
$result->isFirstPage();      // bool
$result->isLastPage();       // bool
```

---

## Display Range ("Showing X–Y of Z")

```php
echo "Showing {$result->getFromNumber()}–{$result->getToNumber()} of {$result->total}";
// e.g. "Showing 21–40 of 85"
```

---

## Accessing Specific Items

```php
$result->first();  // first entity on this page, or null
$result->last();   // last entity on this page, or null
```

---

## Mapping Items

Transform the items array without leaving the result object:

```php
$names = $result->map(fn($product) => $product->name);
// ['Wireless Mouse', 'USB Hub', ...]
```

---

## Page Numbers for UI Controls

`getPageNumbers(int $surroundingPages = 2)` returns an array of page numbers for rendering a pagination bar. Gaps between non-adjacent pages are represented by `null`, which you render as an ellipsis (`…`).

```php
// On page 5 of 12 with surroundingPages = 2:
// [1, null, 3, 4, 5, 6, 7, null, 12]
$pages = $result->getPageNumbers();

echo '<nav class="pagination">';
foreach ($pages as $p) {
    if ($p === null) {
        echo '<span class="ellipsis">…</span>';
    } else {
        $class = $p === $result->page ? 'page-number current' : 'page-number';
        echo '<a href="' . esc_url(add_query_arg('paged', $p)) . '" class="' . $class . '">' . $p . '</a>';
    }
}
echo '</nav>';
```

Adjust the window of surrounding pages as needed:

```php
$result->getPageNumbers(surroundingPages: 1);  // tighter  → [1, null, 4, 5, 6, null, 12]
$result->getPageNumbers(surroundingPages: 3);  // wider    → [1, 2, 3, 4, 5, 6, 7, 8, null, 12]
```

---

## Metadata Array

```php
$result->getMeta();
// [
//   'total'       => 85,
//   'page'        => 2,
//   'per_page'    => 15,
//   'total_pages' => 6,
//   'from'        => 16,
//   'to'          => 30,
// ]
```

---

## Empty Results

Use the static factory instead of checking for `null`:

```php
$result = PaginatedResult::empty(page: 1, perPage: 20);

$result->isEmpty();    // true
$result->total;        // 0
$result->totalPages;   // 0
```

---

## REST API Response

`PaginatedResult` implements `JsonSerializable`, so you can return it directly from a WP REST route:

```php
add_action('rest_api_init', function () {
    register_rest_route('my-plugin/v1', '/products', [
        'methods'             => 'GET',
        'permission_callback' => '__return_true',
        'callback'            => function (WP_REST_Request $request) {
            $result = ProductModel::instance()->paginate(
                page: max(1, (int) $request->get_param('page')),
                perPage: 20,
            );

            return rest_ensure_response($result);
        },
    ]);
});
```

The JSON response shape:

```json
{
    "items": {
        "42": { "id": 42, "name": "Wireless Mouse", "..." : "..." },
        "43": { "id": 43, "name": "USB Hub", "...": "..." }
    },
    "meta": {
        "total": 85,
        "page": 1,
        "per_page": 20,
        "total_pages": 5,
        "from": 1,
        "to": 20
    }
}
```

If you need the array form explicitly:

```php
$result->toArray();  // ['items' => [...], 'meta' => [...]]
```
