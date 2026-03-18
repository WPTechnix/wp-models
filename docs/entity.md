# Entity Reference

An **entity** is a PHP object representing a single database row. Extend `AbstractEntity`, declare your columns and types in `$casts`, and the library handles all type conversion — both when reading from the database and when writing back to it.

---

## Defining an Entity

```php
<?php

use WPTechnix\WPModels\AbstractEntity;

class ProductEntity extends AbstractEntity
{
    protected static string $primaryKey = 'id';

    protected static array $casts = [
        'id'          => 'int',
        'name'        => 'string',
        'price'       => 'decimal',
        'stock'       => 'int',
        'is_active'   => 'bool',
        'tags'        => 'json',
        'status'      => 'enum:ProductStatus',
        'published_at' => 'datetime',
        'created_at'  => 'datetime',
    ];
}
```

Every column you want to access or write **must** appear in `$casts`. Accessing an undeclared column throws a `LogicException`.

### `$primaryKey`

The column that holds the row's unique identifier. Defaults to `'id'`. Must also appear in `$casts`.

---

## Cast Types

| Cast | PHP type on read | Database storage |
|------|-----------------|-----------------|
| `int` | `int` | integer |
| `float` | `float` | float / double |
| `decimal` | `string` | `DECIMAL` or `VARCHAR` |
| `string` | `string` | any text column |
| `bool` | `bool` | `0` or `1` (TINYINT) |
| `datetime` | `DateTimeImmutable` | UTC datetime string |
| `json` | `array` | JSON-encoded string |
| `enum:ClassName` | `BackedEnum` | enum's backing value |

### `decimal` vs `float`

Use `decimal` for money and any value where precision matters. It is stored and returned as a PHP `string` (`'9.99'`, not `9.99`), which avoids floating-point rounding errors.

Use `float` for non-financial numerics where native float precision is acceptable.

---

## Dates and Timezones

Datetime values follow a consistent four-step cycle so you never have to manage timezone conversion yourself:

1. **Stored** in the database as a UTC string (`2024-06-15 14:00:00`)
2. **Hydrated** into a `DateTimeImmutable` object set to UTC
3. **Read** via `__get` with automatic conversion to the **WordPress site timezone** (`Settings → General → Timezone`)
4. **Saved** converted back to UTC before writing to the database

```php
// Reading — always returns a DateTimeImmutable in your WordPress timezone
echo $product->published_at->format('d M Y, H:i');  // e.g. "15 Jun 2024, 16:00" on a UTC+2 site

// Writing — pass any DateTimeInterface; it will be stored as UTC
$product->published_at = new DateTimeImmutable('now');
$product->save();
```

---

## Enum Casting

PHP 8.1 backed enums are supported natively. Define your enum with a string or integer backing type, then reference it in `$casts` using `enum:FullyQualifiedClassName`:

```php
enum ProductStatus: string
{
    case Draft     = 'draft';
    case Published = 'published';
    case Archived  = 'archived';
}
```

```php
protected static array $casts = [
    'status' => 'enum:ProductStatus',
    // ...
];
```

```php
$product->status;                            // ProductStatus::Draft

$product->status = ProductStatus::Published;
$product->save();                            // stores 'published' in the database

if ($product->status === ProductStatus::Published) {
    // ...
}
```

---

## Reading Properties

Access typed values directly by column name using standard property syntax:

```php
$product = ProductModel::instance()->find(1);

$product->id;           // int
$product->name;         // string
$product->price;        // string '9.99'
$product->is_active;    // bool
$product->tags;         // array
$product->created_at;   // DateTimeImmutable (WP timezone)
```

Explicit accessors are also available:

```php
$product->getAttribute('name');  // same as $product->name
$product->getAttributes();       // all typed attributes as an array
```

---

## Writing Properties

Assign values using standard property syntax. The cast is applied on assignment and changes are held in memory until you call `save()`:

```php
$product->name      = 'Wireless Keyboard';
$product->price     = '39.99';
$product->is_active = true;

// Explicit form
$product->setAttribute('name', 'Wireless Keyboard');
```

---

## Dirty Tracking

After a row is fetched from the database, the entity tracks which properties have been changed:

```php
$product = ProductModel::instance()->find(1);

$product->isDirty();                    // false

$product->name = 'New Name';

$product->isDirty();                    // true
$product->isAttributeDirty('name');     // true
$product->isAttributeDirty('price');    // false

$product->getOriginal('name');          // original DB value: 'Old Name'
```

`save()` includes only dirty attributes in the SQL `UPDATE`, so unchanged columns are never touched.

---

## Saving Changes

### `save(): bool`

Persists all dirty attributes to the database. Returns `true` on success.

- If the entity was fetched from the database, `save()` runs an `UPDATE`.
- If the entity was constructed without a primary key value (see below), `save()` runs an `INSERT` and sets the primary key on the object.

```php
// Updating an existing row
$product->name  = 'Updated Name';
$product->price = '49.99';
$product->save();

// Inserting a new row
$product        = new ProductEntity();
$product->name  = 'Brand New Product';
$product->price = '29.99';
$product->save();

echo $product->id;  // set after insert
```

> **Note:** `save()` requires the entity to be linked to its model. Entities fetched through a model are linked automatically. When constructing an entity manually with `new`, call `$product->save()` only after `ProductEntity::create()` has associated it with the model, or use `ProductModel::instance()->create($data)` instead, which is the more straightforward approach for most cases.

---

## Deleting a Row

### `delete(): bool`

Removes the row from the database. After deletion, `exists()` returns `false`.

```php
$product->delete();
$product->exists();  // false
```

---

## Existence Checks

```php
$product->isNew();                         // true if no primary key is set (never persisted)
$product->exists();                        // checks the in-memory primary key value
$product->exists(forceCheckInDb: true);    // always queries the database
```

---

## Refreshing from the Database

Reloads the entity's attributes from the database, discarding any local changes:

```php
$product->refresh();
```

---

## Serialisation

```php
$product->toArray();    // ['id' => 1, 'name' => '...', ...]
json_encode($product);  // same output via JsonSerializable
```

Serialisation rules:
- `datetime` — ISO-8601 string
- `enum` — the enum's backing string or integer value
- `json` — the decoded array (not re-encoded)

---

## Cloning

Cloning creates an independent copy of the entity with its primary key cleared, marking it as new. Useful for creating a new row based on an existing one:

```php
$copy = clone $product;

$copy->isNew();  // true
$copy->name = 'Copy of ' . $product->name;
$copy->save();   // inserts a new row
```

---

## Accessing the Model from an Entity

If you need the model instance from within an entity:

```php
$model = $product->getModel();  // ProductModel
$model->find(2);
```
