# Caching

WP Models automatically caches query results using the standard WordPress object cache (`wp_cache_*`). In the common case — read, write, read again — you do not need to think about caching at all. This page explains what is cached, what clears it, and what to do in the rare cases where you need to act manually.

---

## How It Works

There are two independent cache layers. Each model class has its own isolated cache namespace, so separate models never interfere with one another.

**L1 — Query Cache**
Stores the list of primary key IDs returned by a query. The cache key is derived from the SQL and its bound values. When the same query runs again, the IDs are served from cache and only entity hydration needs to access the database (via L2 below).

**L2 — Entity Cache**
Stores the raw database row for each entity, keyed by primary key. A call to `find(42)` on a warm cache never touches the database.

```
findWhere([...])
  ├── L1 hit  → IDs from cache
  │               └── per ID: L2 hit → hydrate entity
  │                           L2 miss → DB fetch → store in L2 → hydrate entity
  │
  └── L1 miss → DB query → store IDs in L1
                  └── per ID: L2 hit → hydrate entity
                              L2 miss → DB fetch → store in L2 → hydrate entity
```

`findMany` uses `wp_cache_get_multiple` (available since WordPress 5.5) to batch L2 lookups, then fetches only the remaining IDs from the database.

---

## Cache Backends

Both layers use the standard WordPress object cache API, so the active backend depends entirely on your hosting environment:

| Environment | Behaviour |
|-------------|-----------|
| No object cache drop-in | PHP process memory — cache is cleared at the end of each request |
| Redis or Memcached drop-in (e.g. `redis-cache`) | Persistent cross-request cache — L2 hits are far more valuable |

No configuration is required in this library. Install a persistent object cache drop-in and it benefits immediately.

---

## What Clears the Cache

Write operations maintain consistency automatically:

| Operation | L1 (query cache) | L2 (entity cache) |
|-----------|-----------------|-------------------|
| `create` | Cleared | — |
| `createMany` | Cleared | — |
| `update(id, …)` | Cleared | Cleared for that ID |
| `delete(id)` | Cleared | Cleared for that ID |
| `increment` / `decrement` | Cleared | Cleared for that ID |
| `updateWhere` | Cleared | **Not cleared** (see below) |
| `deleteWhere` | Cleared | **Not cleared** (see below) |

---

## Bulk Operation Cache Caveat

`updateWhere` and `deleteWhere` clear the L1 query cache but intentionally skip per-entity L2 invalidation. Fetching all affected IDs before every bulk write would add an extra round-trip to the database in every case — an unnecessary cost for what is usually a background or admin operation.

**In practice:** if you run `updateWhere` and then immediately `find()` an entity that was affected, you may receive a stale cached version until the L2 entry expires or is explicitly cleared.

If that matters for your use case, two options are available:

**Option A — clear specific entity caches after a bulk write:**

```php
// Collect the IDs you are about to update
$affected = array_keys($products->findWhere($conditions));

// Run the bulk update
$products->updateWhere(['status' => 'archived'], $conditions);

// Invalidate L2 for those specific entities
$products->clearEntityCaches($affected);
```

**Option B — bypass cache entirely for the write:**

Passing `invalidate: false` skips all cache operations. Useful in import scripts or migrations where you plan to rebuild the cache in one step at the end.

```php
$products->updateWhere(['status' => 'archived'], $conditions, invalidate: false);

// After all writes are done, invalidate the query cache once
$products->invalidateQueryCache();
```

---

## Manual Cache Control

In normal use these are rarely needed, but they are available:

```php
// Rotate the L1 salt — instantly stales all cached query ID lists for this model
$products->invalidateQueryCache();

// Remove specific rows from the L2 entity cache
$products->clearEntityCaches([1, 2, 3]);
```

---

## Bypassing Cache on Individual Writes

Every write method accepts an `$invalidate` parameter that defaults to `true`. Set it to `false` to run the database operation without any cache interaction:

```php
// No cache reads or writes for these operations
$products->create($data,       invalidate: false);
$products->update($id, $data,  invalidate: false);
$products->delete($id,         invalidate: false);

// Invalidate the query cache once after all writes are done
$products->invalidateQueryCache();
```
