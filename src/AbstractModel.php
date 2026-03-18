<?php

declare(strict_types=1);

namespace WPTechnix\WPModels;

use Generator;
use InvalidArgumentException;
use LogicException;
use wpdb;

defined('ABSPATH') || exit;

/**
 * Abstract Model Class - Practical Data Access Layer.
 *
 * Provides type-safe CRUD operations with filtered queries, pagination,
 * and efficient caching for WordPress plugin development.
 *
 * Design Philosophy:
 * - **Prime Cache Strategy**: Queries fetch IDs first, then hydrate entities via `findMany`.
 * - **Raw Data Caching**: Stores lightweight arrays in cache; hydrates Entities on retrieval.
 * - **Batch Optimization**: Supports `wp_cache_get_multiple` and controlled invalidation.
 *
 * Cache Architecture:
 * - **L1 Cache (Query Cache)**: Stores query result IDs, invalidated via salt rotation.
 * - **L2 Cache (Entity Cache)**: Stores raw row arrays by primary key.
 *
 * Important Notes on Bulk Operations:
 * - `deleteWhere()` and `updateWhere()` invalidate L1 query cache but NOT individual L2 entity caches.
 * - This is by design for performance. If you need cache consistency after bulk operations,
 *   either fetch affected IDs first and clear them, or call `clearEntityCaches()` with specific IDs.
 *
 * @template TEntity of AbstractEntity = AbstractEntity
 */
abstract class AbstractModel
{
    /**
     * Singleton instances.
     *
     * @var array<class-string<AbstractModel>,AbstractModel>
     */
    private static array $instances = [];

    /**
     * WordPress database object.
     *
     * @var wpdb
     */
    protected wpdb $wpdb;

    /**
     * Table name without WordPress prefix.
     *
     * @var string
     */
    protected string $tableName = '';

    /**
     * Cache group for WordPress object caching.
     *
     * @var string
     */
    protected string $cacheGroup = '';

    /**
     * Cache expiration in seconds. 0 = persistent.
     *
     * @var int<0, max>
     */
    protected int $cacheExpiration = 0;

    /**
     * Entity class for hydration.
     *
     * @var class-string<TEntity>
     */
    protected string $entityClass;

    /**
     * Primary key column name.
     *
     * @var non-empty-string
     */
    protected string $primaryKey = 'id';

    /**
     * Columns allowed for write operations.
     *
     * @var list<non-empty-string>
     */
    protected array $fillable = [];

    /**
     * Columns allowed for filtering/querying.
     * If empty, defaults to fillable + primaryKey.
     *
     * @var list<non-empty-string>
     */
    protected array $queryable = [];

    /**
     * Default ORDER BY for queries.
     *
     * @var array<string, 'ASC'|'DESC'>
     */
    protected array $defaultOrderBy = ['id' => 'DESC'];

    /**
     * Use network-wide table in multisite.
     *
     * @var bool
     */
    protected bool $networkWide = false;

    /**
     * Chunk size for batch operations.
     *
     * @var positive-int
     */
    protected int $chunkSize = 1000;

    /**
     * Last error message from database operation.
     *
     * @var string|null
     */
    protected ?string $lastError = null;

    /**
     * Cached resolved table name.
     *
     * @var string|null
     */
    private ?string $resolvedTableName = null;

    /**
     * Cached queryable columns set.
     *
     * @var array<string, true>|null
     */
    private ?array $queryableSet = null;

    /**
     * Clause Builder instance.
     *
     * @var ClauseBuilder|null
     */
    private ?ClauseBuilder $clauseBuilder = null;

    /**
     * Protected constructor for singleton pattern.
     */
    final private function __construct()
    {
        global $wpdb;

        if (! $wpdb instanceof wpdb) {
            throw new LogicException('WordPress database not available.');
        }

        $this->wpdb = $wpdb;
        $this->validateConfiguration();
    }

    /**
     * Get singleton instance.
     *
     * @return static
     */
    final public static function instance(): static
    {
        if (! isset(self::$instances[static::class])) {
            // @phpstan-ignore new.staticInAbstractClassStaticMethod
            self::$instances[static::class] = new static();
        }

        /** @var static $instance */
        $instance = self::$instances[static::class];

        return $instance;
    }

    /**
     * Get last error message from database operation.
     *
     * Returns the error message from the most recent failed operation,
     * or null if the last operation succeeded.
     *
     * @return string|null Error message or null.
     */
    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    /**
     * Find entity by primary key.
     *
     * @param int $id Primary key value.
     *
     * @return TEntity|null
     */
    public function find(int $id): ?AbstractEntity
    {
        if ($id <= 0) {
            return null;
        }

        // Check L2 cache first (hydrates from raw array).
        $cached = $this->getFromCache($id);

        if ($cached instanceof AbstractEntity) {
            /** @var TEntity $cached */
            return $cached;
        }

        if ($cached === 0) {
            // Negative cache hit.
            return null;
        }

        // Fetch from database.
        $tableName = $this->getTableName();
        $pk = $this->primaryKey;

		// phpcs:disable WordPress.DB.PreparedSQL
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare("SELECT * FROM `{$tableName}` WHERE `{$pk}` = %d LIMIT 1", $id),
            ARRAY_A,
        );
		// phpcs:enable

        if (! is_array($row) || $row === []) {
            // Negative cache.
            $this->setCache($id, 0);

            return null;
        }

        $entity = $this->toEntity($row);
        // Store raw array.
        $this->setCache($id, $row);

        /** @var TEntity|null $entity */
        return $entity;
    }

    /**
     * Find multiple entities by primary keys (Optimized).
     *
     * Uses wp_cache_get_multiple to reduce network round-trips to Redis/Memcached.
     *
     * @param list<int> $ids Primary key values.
     *
     * @return array<int, TEntity> Map of ID => Entity.
     */
    public function findMany(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $cleanIds = $this->sanitizeIds($ids);

        if ($cleanIds === []) {
            return [];
        }

        $results = [];
        $missingIds = [];
        $cacheKeys = [];

        // 1. Prepare keys for Batch Get.
        foreach ($cleanIds as $id) {
            $cacheKeys[$id] = (string) $id;
        }

        // 2. Batch Fetch from Cache.
        $foundInCache = [];

        if ($this->cacheGroup !== '' && function_exists('wp_cache_get_multiple')) {
            $foundInCache = wp_cache_get_multiple(array_values($cacheKeys), $this->cacheGroup);
        } elseif ($this->cacheGroup !== '') {
            // Fallback for environments without wp_cache_get_multiple.
            foreach ($cacheKeys as $id => $key) {
                $found = false;
                $val = wp_cache_get($key, $this->cacheGroup, false, $found);

                if ($found !== null && $found !== false) {
                    $foundInCache[$key] = $val;
                }
            }
        }

        // 3. Process Hits and Identify Misses.
        foreach ($cleanIds as $id) {
            $key = (string) $id;

            if (isset($foundInCache[$key])) {
                $raw = $foundInCache[$key];

                if ($raw === 0) {
                    // Negative cache hit.
                    continue;
                }

                if (is_array($raw)) {
                    $entity = $this->toEntity($raw);

                    if ($entity !== null) {
                        $results[$id] = $entity;
                    }
                } else {
                    $missingIds[] = $id;
                }
            } else {
                $missingIds[] = $id;
            }
        }

        // 4. Fetch Missing from DB.
        if ($missingIds !== []) {
            $fetched = $this->fetchByIds($missingIds);

            foreach ($missingIds as $id) {
                if (isset($fetched[$id])) {
                    $entity = $this->toEntity($fetched[$id]);

                    if ($entity !== null) {
                        $results[$id] = $entity;
                        $this->setCache($id, $fetched[$id]);
                    }
                } else {
                    $this->setCache($id, 0);
                }
            }
        }

        /** @var array<int, TEntity> $results */
        return $results;
    }

    /**
     * Check if entity exists.
     *
     * @param int $id Primary key value.
     *
     * @return bool
     */
    public function exists(int $id): bool
    {
        if ($id <= 0) {
            return false;
        }

        // Check cache existence via getFromCache logic.
        $cached = $this->getFromCache($id);

        if ($cached instanceof AbstractEntity) {
            return true;
        }

        if ($cached === 0) {
            return false;
        }

        $tableName = $this->getTableName();
        $pk = $this->primaryKey;

		// phpcs:disable WordPress.DB.PreparedSQL
        $exists = $this->wpdb->get_var(
            $this->wpdb->prepare("SELECT 1 FROM `{$tableName}` WHERE `{$pk}` = %d LIMIT 1", $id),
        );
		// phpcs:enable

        if ($exists !== '1') {
            $this->setCache($id, 0);

            return false;
        }

        return true;
    }

    /**
     * Create new record.
     *
     * @param array<string, mixed> $data       Column => value pairs.
     * @param bool                 $invalidate Whether to invalidate L1 query cache (set false for batch ops).
     *
     * @return int|false New ID on success, false on failure.
     */
    public function create(array $data, bool $invalidate = true): int|false
    {
        $this->lastError = null;

        $sanitized = $this->filterFillable($data);

        if ($sanitized === []) {
            $this->lastError = 'No fillable data provided for insert.';

            return false;
        }

        $result = $this->wpdb->insert(
            $this->getTableName(),
            $sanitized,
            $this->getDataFormat($sanitized),
        );

        if ($result === false) {
            $this->lastError = $this->wpdb->last_error !== ''
                ? $this->wpdb->last_error
                : 'Database insert failed.';

            return false;
        }

        if ($this->wpdb->insert_id <= 0) {
            $this->lastError = 'Insert succeeded but no ID was returned.';

            return false;
        }

        $id = $this->wpdb->insert_id;
        $this->clearCache($id);

        if ($invalidate) {
            $this->invalidateQueryCache();
        }

        return $id;
    }

    /**
     * Create multiple records in a single query (Bulk Insert).
     *
     * Efficient for large data sets. Automatically chunks requests to avoid packet limits.
     * Note: Does not return IDs of created rows due to MySQL limitations in bulk inserts.
     *
     * @param array<int, array<string, mixed>> $rows       List of data arrays.
     * @param bool                             $invalidate Whether to invalidate L1 query cache.
     *
     * @return int Number of rows inserted.
     */
    public function createMany(array $rows, bool $invalidate = true): int
    {
        $this->lastError = null;

        if ($rows === []) {
            return 0;
        }

        $tableName = $this->getTableName();
        $totalInserted = 0;
        $qb = $this->getClauseBuilder();

        foreach (array_chunk($rows, $this->chunkSize) as $chunk) {
            $firstRow = reset($chunk);

            if (! is_array($firstRow)) {
                continue;
            }

            $sanitizedFirst = $this->filterFillable($firstRow);

            if ($sanitizedFirst === []) {
                continue;
            }

            $columns = array_keys($sanitizedFirst);
            $columnList = '`' . implode('`, `', $columns) . '`';

            $sqlValues = [];
            $prepareParams = [];

            foreach ($chunk as $row) {
                if (! is_array($row)) {
                    continue;
                }

                $sanitized = $this->filterFillable($row);

                // Ensure row has same keys as columns; fill missing with null.
                $rowPlaceholders = [];

                foreach ($columns as $col) {
                    $val = $sanitized[$col] ?? null;
                    $prepareParams[] = $val;
                    $rowPlaceholders[] = $qb->getPlaceholder($val);
                }

                $sqlValues[] = '(' . implode(', ', $rowPlaceholders) . ')';
            }

            if ($sqlValues === []) {
                continue;
            }

            $sql = "INSERT INTO `{$tableName}` ({$columnList}) VALUES " . implode(', ', $sqlValues);

			// phpcs:ignore WordPress.DB.PreparedSQL
            $prepared = (string) $this->wpdb->prepare($sql, ...$prepareParams);

			// phpcs:ignore WordPress.DB.PreparedSQL
            $result = $this->wpdb->query($prepared);

            if ($result === false) {
                $this->lastError = $this->wpdb->last_error !== ''
                    ? $this->wpdb->last_error
                    : 'Bulk insert failed.';
            } elseif (is_int($result)) {
                $totalInserted += $result;
            }
        }

        if ($invalidate && $totalInserted > 0) {
            $this->invalidateQueryCache();
        }

        return $totalInserted;
    }

    /**
     * Update existing record.
     *
     * @param int                  $id         Primary key.
     * @param array<string, mixed> $data       Column => value pairs.
     * @param bool                 $invalidate Whether to invalidate L1 query cache.
     *
     * @return bool True on success, false on failure.
     */
    public function update(int $id, array $data, bool $invalidate = true): bool
    {
        $this->lastError = null;

        if ($id <= 0) {
            $this->lastError = 'Invalid ID provided for update.';

            return false;
        }

        $sanitized = $this->filterFillable($data);

        if ($sanitized === []) {
            $this->lastError = 'No fillable data provided for update.';

            return false;
        }

        $result = $this->wpdb->update(
            $this->getTableName(),
            $sanitized,
            [$this->primaryKey => $id],
            $this->getDataFormat($sanitized),
            ['%d'],
        );

        if ($result === false) {
            $this->lastError = $this->wpdb->last_error !== ''
                ? $this->wpdb->last_error
                : 'Database update failed.';

            return false;
        }

        $this->clearCache($id);

        if ($invalidate) {
            $this->invalidateQueryCache();
        }

        return true;
    }

    /**
     * Delete record by ID.
     *
     * @param int  $id         Primary key.
     * @param bool $invalidate Whether to invalidate L1 query cache.
     *
     * @return bool True if deleted, false otherwise.
     */
    public function delete(int $id, bool $invalidate = true): bool
    {
        $this->lastError = null;

        if ($id <= 0) {
            $this->lastError = 'Invalid ID provided for delete.';

            return false;
        }

        $result = $this->wpdb->delete(
            $this->getTableName(),
            [$this->primaryKey => $id],
            ['%d'],
        );

        if ($result === false) {
            $this->lastError = $this->wpdb->last_error !== ''
                ? $this->wpdb->last_error
                : 'Database delete failed.';

            return false;
        }

        if ($result === 0) {
            $this->lastError = 'No record found with the specified ID.';

            return false;
        }

        $this->clearCache($id);

        if ($invalidate) {
            $this->invalidateQueryCache();
        }

        return true;
    }

    /**
     * Find entities matching conditions using Prime Cache strategy.
     *
     * @param array<int|string, mixed>   $conditions Query conditions.
     * @param array<string, string>|null $orderBy    ORDER BY clause (Col => Direction).
     * @param int|null                   $limit      Maximum results.
     *
     * @return array<int, TEntity> Map of ID => Entity.
     */
    public function findWhere(array $conditions, ?array $orderBy = null, ?int $limit = null): array
    {
        $tableName = $this->getTableName();
        $pk = $this->primaryKey;
        $orderBy ??= $this->defaultOrderBy;
        $qb = $this->getClauseBuilder();

        // 1. Attempt to get IDs from Query Cache (L1).
        $cacheKey = $this->getQueryCacheKey('ids', $conditions, $orderBy, $limit);
        $cachedIds = $this->getFromQueryCache($cacheKey);

        if (is_array($cachedIds)) {
            $ids = array_map(static fn ($v) => is_scalar($v) ? (int) $v : 0, $cachedIds);
        } else {
            [$whereClause, $values] = $qb->buildWhere($conditions);
            $orderClause = $qb->buildOrderBy($orderBy);

            $sql = "SELECT `{$pk}` FROM `{$tableName}` WHERE {$whereClause} ORDER BY {$orderClause}";

            if ($limit !== null && $limit > 0) {
                $sql .= $this->wpdb->prepare(' LIMIT %d', $limit);
            }

            if ($values !== []) {
				// phpcs:ignore WordPress.DB.PreparedSQL
                $sql = (string) $this->wpdb->prepare($sql, ...$values);
            }

			// phpcs:ignore WordPress.DB.PreparedSQL
            $results = $this->wpdb->get_col($sql);

            if (! is_array($results)) {
                return [];
            }

            $ids = array_map(static fn ($v) => is_scalar($v) ? (int) $v : 0, $results);
            $this->setQueryCache($cacheKey, $ids);
        }

        if ($ids === []) {
            return [];
        }

        /** @var list<int> $ids */
        // 2. Prime Entity Cache (L2) and Hydrate.
        $entities = $this->findMany($ids);

        // 3. Re-order results to match the query order.
        $orderedResults = [];

        foreach ($ids as $id) {
            if (isset($entities[$id])) {
                $orderedResults[$id] = $entities[$id];
            }
        }

        /** @var array<int, TEntity> $orderedResults */
        return $orderedResults;
    }

    /**
     * Find single entity matching conditions.
     *
     * @param array<int|string, mixed>   $conditions Query conditions.
     * @param array<string, string>|null $orderBy    ORDER BY clause.
     *
     * @return TEntity|null
     */
    public function findOneWhere(array $conditions, ?array $orderBy = null): ?AbstractEntity
    {
        $results = $this->findWhere($conditions, $orderBy, 1);

        if ($results === []) {
            return null;
        }

        return reset($results);
    }

    /**
     * Find entities by single column value.
     *
     * @param string $column Column name.
     * @param mixed  $value  Value to match.
     *
     * @return array<int, TEntity>
     */
    public function findBy(string $column, mixed $value): array
    {
        return $this->findWhere([
            ['column' => $column, 'value' => $value],
        ]);
    }

    /**
     * Find single entity by column value.
     *
     * @param string $column Column name.
     * @param mixed  $value  Value to match.
     *
     * @return TEntity|null
     */
    public function findOneBy(string $column, mixed $value): ?AbstractEntity
    {
        return $this->findOneWhere([
            ['column' => $column, 'value' => $value],
        ]);
    }

    /**
     * Find first record matching attributes or create new one.
     *
     * @param array<string, mixed> $attributes Key-value pairs to search for.
     * @param array<string, mixed> $values     Additional values to use when creating.
     *
     * @return TEntity|null Entity on success, null on creation failure.
     */
    public function firstOrCreate(array $attributes, array $values = []): ?AbstractEntity
    {
        $this->lastError = null;

        if ($attributes === []) {
            $this->lastError = 'Attributes cannot be empty for firstOrCreate.';

            return null;
        }

        // Convert simple key-value pairs to condition format.
        $conditions = $this->getClauseBuilder()->attributesToConditions($attributes);

        // Try to find existing record.
        $existing = $this->findOneWhere($conditions);

        if ($existing !== null) {
            return $existing;
        }

        // Merge attributes and additional values for creation.
        $createData = array_merge($attributes, $values);

        $id = $this->create($createData);

        if ($id === false) {
            // lastError already set by create().
            return null;
        }

        /** @var TEntity|null $entity */
        $entity = $this->find($id);

        return $entity;
    }

    /**
     * Update existing record matching attributes or create new one.
     *
     * @param array<string, mixed> $attributes Key-value pairs to search for.
     * @param array<string, mixed> $values     Values to update or create with.
     *
     * @return TEntity|null Entity on success, null on failure.
     */
    public function updateOrCreate(array $attributes, array $values): ?AbstractEntity
    {
        $this->lastError = null;

        if ($attributes === []) {
            $this->lastError = 'Attributes cannot be empty for updateOrCreate.';

            return null;
        }

        // Convert simple key-value pairs to condition format.
        $conditions = $this->getClauseBuilder()->attributesToConditions($attributes);

        // Try to find existing record.
        $existing = $this->findOneWhere($conditions);

        if ($existing !== null) {
            $pk = $existing->getPkValue();

            if ($pk === null) {
                $this->lastError = 'Existing entity has no primary key.';

                return null;
            }

            // Update with new values.
            if (! $this->update($pk, $values)) {
                // lastError already set by update().
                return null;
            }

            /** @var TEntity|null $entity */
            $entity = $this->find($pk);

            return $entity;
        }

        // Create new record with attributes + values.
        $createData = array_merge($attributes, $values);

        $id = $this->create($createData);

        if ($id === false) {
            // lastError already set by create().
            return null;
        }

        /** @var TEntity|null $entity */
        $entity = $this->find($id);

        return $entity;
    }

    /**
     * Get paginated results using Prime Cache strategy.
     *
     * @param int                        $page       1-indexed page number.
     * @param int                        $perPage    Items per page.
     * @param array<int|string, mixed>   $conditions Filter conditions.
     * @param array<string, string>|null $orderBy    ORDER BY clause.
     *
     * @return PaginatedResult<TEntity>
     */
    public function paginate(
        int $page,
        int $perPage,
        array $conditions = [],
        ?array $orderBy = null,
    ): PaginatedResult {
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $offset = ($page - 1) * $perPage;
        $orderBy ??= $this->defaultOrderBy;
        $qb = $this->getClauseBuilder();

        // 1. Get Total Count.
        $total = $this->countWhere($conditions);

        if ($total === 0) {
            /** @var array<int, TEntity> $empty */
            $empty = [];

            return new PaginatedResult($empty, 0, $page, $perPage, 0);
        }

        // 2. Get IDs for this Page.
        $cacheKey = $this->getQueryCacheKey('page_ids', $conditions, $orderBy, $perPage, $offset);
        $cachedIds = $this->getFromQueryCache($cacheKey);

        if (is_array($cachedIds)) {
            $ids = array_map(static fn ($v) => is_scalar($v) ? (int) $v : 0, $cachedIds);
        } else {
            $tableName = $this->getTableName();
            $pk = $this->primaryKey;
            [$whereClause, $values] = $qb->buildWhere($conditions);
            $orderClause = $qb->buildOrderBy($orderBy);

            $sql = "SELECT `{$pk}` FROM `{$tableName}` WHERE {$whereClause} ORDER BY {$orderClause} LIMIT %d OFFSET %d";
            $queryValues = array_merge($values, [$perPage, $offset]);

			// phpcs:ignore WordPress.DB.PreparedSQL
            $results = $this->wpdb->get_col($this->wpdb->prepare($sql, ...$queryValues));

            $ids = ! is_array($results) ? [] : array_map(static fn ($v) => is_scalar($v) ? (int) $v : 0, $results);

            $this->setQueryCache($cacheKey, $ids);
        }

        /** @var list<int> $ids */
        // 3. Hydrate.
        $entities = $this->findMany($ids);

        $items = [];

        foreach ($ids as $id) {
            if (isset($entities[$id])) {
                $items[$id] = $entities[$id];
            }
        }

        $totalPages = (int) ceil($total / $perPage);

        /** @var array<int, TEntity> $items */
        return new PaginatedResult($items, $total, $page, $perPage, $totalPages);
    }

    /**
     * Count records matching conditions.
     *
     * @param array<int|string, mixed> $conditions Filter conditions.
     *
     * @return int<0, max>
     */
    public function countWhere(array $conditions = []): int
    {
        $cacheKey = $this->getQueryCacheKey('count', $conditions, [], null);
        $cachedCount = $this->getFromQueryCache($cacheKey);

        if (is_int($cachedCount)) {
            return max(0, $cachedCount);
        }

        $tableName = $this->getTableName();
        [$whereClause, $values] = $this->getClauseBuilder()->buildWhere($conditions);

        $sql = "SELECT COUNT(*) FROM `{$tableName}` WHERE {$whereClause}";

        if ($values !== []) {
			// phpcs:ignore WordPress.DB.PreparedSQL
            $sql = (string) $this->wpdb->prepare($sql, ...$values);
        }

		// phpcs:ignore WordPress.DB.PreparedSQL
        $count = max(0, (int) $this->wpdb->get_var($sql));

        $this->setQueryCache($cacheKey, $count);

        return $count;
    }

    /**
     * Delete records matching conditions.
     *
     * Note: This method invalidates query cache (L1) but does NOT clear individual
     * entity caches (L2) for performance reasons. If cache consistency is critical,
     * fetch affected IDs first and use clearEntityCaches().
     *
     * @param array<int|string, mixed> $conditions Filter conditions (required).
     * @param bool                     $invalidate Whether to invalidate L1 query cache.
     *
     * @return int Number of deleted rows.
     */
    public function deleteWhere(array $conditions, bool $invalidate = true): int
    {
        $this->lastError = null;

        if ($conditions === []) {
            throw new InvalidArgumentException('Conditions required for bulk delete.');
        }

        $tableName = $this->getTableName();
        [$whereClause, $values] = $this->getClauseBuilder()->buildWhere($conditions);

        $sql = "DELETE FROM `{$tableName}` WHERE {$whereClause}";

        if ($values !== []) {
			// phpcs:ignore WordPress.DB.PreparedSQL
            $sql = (string) $this->wpdb->prepare($sql, ...$values);
        }

		// phpcs:ignore WordPress.DB.PreparedSQL
        $result = $this->wpdb->query($sql);

        if ($result === false) {
            $this->lastError = $this->wpdb->last_error !== ''
                ? $this->wpdb->last_error
                : 'Bulk delete failed.';

            return 0;
        }

        if ($invalidate) {
            $this->invalidateQueryCache();
        }

        return (int) $result;
    }

    /**
     * Update records matching conditions.
     *
     * Note: This method invalidates query cache (L1) but does NOT clear individual
     * entity caches (L2) for performance reasons. If cache consistency is critical,
     * fetch affected IDs first and use clearEntityCaches().
     *
     * @param array<string, mixed>     $data       Column => value pairs to update.
     * @param array<int|string, mixed> $conditions Filter conditions (required).
     * @param bool                     $invalidate Whether to invalidate L1 query cache.
     *
     * @return int Number of updated rows.
     */
    public function updateWhere(array $data, array $conditions, bool $invalidate = true): int
    {
        $this->lastError = null;

        if ($conditions === []) {
            throw new InvalidArgumentException('Conditions required for bulk update.');
        }

        $sanitized = $this->filterFillable($data);

        if ($sanitized === []) {
            $this->lastError = 'No fillable data provided for bulk update.';

            return 0;
        }

        $tableName = $this->getTableName();
        $qb = $this->getClauseBuilder();

        // Build SET clause with proper type placeholders.
        $setClauses = [];
        $setValues = [];

        foreach ($sanitized as $column => $value) {
            $placeholder = $qb->getPlaceholder($value);
            $setClauses[] = "`{$column}` = {$placeholder}";
            $setValues[] = $value;
        }

        $setClause = implode(', ', $setClauses);

        [$whereClause, $whereValues] = $qb->buildWhere($conditions);

        $sql = "UPDATE `{$tableName}` SET {$setClause} WHERE {$whereClause}";

        $allValues = array_merge($setValues, $whereValues);

		// phpcs:ignore WordPress.DB.PreparedSQL
        $sql = (string) $this->wpdb->prepare($sql, ...$allValues);

		// phpcs:ignore WordPress.DB.PreparedSQL
        $result = $this->wpdb->query($sql);

        if ($result === false) {
            $this->lastError = $this->wpdb->last_error !== ''
                ? $this->wpdb->last_error
                : 'Bulk update failed.';

            return 0;
        }

        if ($invalidate) {
            $this->invalidateQueryCache();
        }

        return (int) $result;
    }

    /**
     * Get values of a single column from matching records.
     *
     * @param string                   $column     Column to retrieve.
     * @param array<int|string, mixed> $conditions Filter conditions (empty = all records).
     * @param string|null              $keyBy      Optional column to use as array keys.
     *
     * @return array<int|string, mixed> List of values, or map of keyBy => value.
     */
    public function pluck(string $column, array $conditions = [], ?string $keyBy = null): array
    {
        $queryable = $this->getQueryableSet();

        if (! isset($queryable[$column])) {
            throw new InvalidArgumentException("Column not queryable: {$column}");
        }

        if ($keyBy !== null && ! isset($queryable[$keyBy])) {
            throw new InvalidArgumentException("Key column not queryable: {$keyBy}");
        }

        $tableName = $this->getTableName();
        $selectColumns = $keyBy !== null ? "`{$keyBy}`, `{$column}`" : "`{$column}`";

        [$whereClause, $values] = $this->getClauseBuilder()->buildWhere($conditions);

        $sql = "SELECT {$selectColumns} FROM `{$tableName}` WHERE {$whereClause}";

        if ($values !== []) {
			// phpcs:ignore WordPress.DB.PreparedSQL
            $sql = (string) $this->wpdb->prepare($sql, ...$values);
        }

        if ($keyBy !== null) {
			// phpcs:ignore WordPress.DB.PreparedSQL
            $results = $this->wpdb->get_results($sql, ARRAY_A);
            /** @var list<array<string,scalar>> $results */

            if (! is_array($results)) {
                return [];
            }

            $plucked = [];

            foreach ($results as $row) {
                if (is_array($row) && isset($row[$keyBy], $row[$column])) {
                    $plucked[is_numeric($row[$keyBy]) ? (int) $row[$keyBy] : $row[$keyBy]] = $row[$column];
                }
            }

            return $plucked;
        }

		// phpcs:ignore WordPress.DB.PreparedSQL
        $results = $this->wpdb->get_col($sql);

        return is_array($results) ? $results : [];
    }

    /**
     * Atomically increment a column value.
     *
     * Uses SQL increment to avoid race conditions in concurrent updates.
     *
     * @param int    $id         Primary key.
     * @param string $column     Column to increment (must be numeric type).
     * @param int    $amount     Amount to increment by (use negative for decrement).
     * @param bool   $invalidate Whether to invalidate caches.
     *
     * @return bool True on success, false on failure.
     */
    public function increment(int $id, string $column, int $amount = 1, bool $invalidate = true): bool
    {
        $this->lastError = null;

        if ($id <= 0) {
            $this->lastError = 'Invalid ID provided for increment.';

            return false;
        }

        if ($amount === 0) {
            // No-op.
            return true;
        }

        $queryable = $this->getQueryableSet();

        if (! isset($queryable[$column])) {
            throw new InvalidArgumentException("Column not queryable: {$column}");
        }

        $tableName = $this->getTableName();
        $pk = $this->primaryKey;

        // Use atomic SQL increment to avoid race conditions.
		// phpcs:disable WordPress.DB.PreparedSQL
        $sql = (string) $this->wpdb->prepare(
            "UPDATE `{$tableName}` SET `{$column}` = `{$column}` + %d WHERE `{$pk}` = %d",
            $amount,
            $id,
        );

        $result = $this->wpdb->query($sql);
		// phpcs:enable

        if ($result === false) {
            $this->lastError = $this->wpdb->last_error !== ''
                ? $this->wpdb->last_error
                : 'Increment operation failed.';

            return false;
        }

        if ($result === 0) {
            $this->lastError = 'No record found with the specified ID.';

            return false;
        }

        $this->clearCache($id);

        if ($invalidate) {
            $this->invalidateQueryCache();
        }

        return true;
    }

    /**
     * Atomically decrement a column value.
     *
     * Convenience wrapper around increment() with negative amount.
     *
     * @param int    $id         Primary key.
     * @param string $column     Column to decrement (must be numeric type).
     * @param int    $amount     Amount to decrement by (positive number).
     * @param bool   $invalidate Whether to invalidate caches.
     *
     * @return bool True on success, false on failure.
     */
    public function decrement(int $id, string $column, int $amount = 1, bool $invalidate = true): bool
    {
        return $this->increment($id, $column, -$amount, $invalidate);
    }

    /**
     * Invalidate query cache (L1) by rotating salt.
     *
     * Public visibility allows external batch processors to trigger invalidation.
     */
    public function invalidateQueryCache(): void
    {
        if ($this->cacheGroup === '') {
            return;
        }

        wp_cache_set(
            'last_changed',
            (string) microtime(true),
            $this->cacheGroup,
            $this->cacheExpiration,
        );
    }

    /**
     * Clear entity caches (L2) for specific IDs.
     *
     * Useful after bulk operations where you need cache consistency.
     *
     * @param list<int> $ids Primary key values to clear from cache.
     */
    public function clearEntityCaches(array $ids): void
    {
        if ($this->cacheGroup === '') {
            return;
        }

        foreach ($ids as $id) {
            if (is_int($id) && $id > 0) {
                $this->clearCache($id);
            }
        }
    }

    /**
     * Get table name with prefix.
     *
     * @return string
     */
    public function getTableName(): string
    {
        if ($this->resolvedTableName !== null) {
            return $this->resolvedTableName;
        }

        $prefix = $this->networkWide ? $this->wpdb->base_prefix : $this->wpdb->prefix;
        $fullName = $prefix . $this->tableName;

        $this->resolvedTableName = $fullName;

        return $fullName;
    }

    /**
     * Get primary key column name.
     *
     * @return non-empty-string
     */
    public function getPrimaryKey(): string
    {
        return $this->primaryKey;
    }

    /**
     * Process records in chunks with a callback.
     *
     * Efficient for batch processing large datasets without memory issues.
     * Each chunk is fetched separately, processed, then discarded.
     *
     * Example:
     * ```php
     * $model->chunk(['status' => 'pending'], function ($entity) {
     *     $entity->status = 'processed';
     *     $entity->save();
     * }, 500);
     * ```
     *
     * @param array<int|string, mixed> $conditions Filter conditions.
     * @param callable(TEntity): void  $callback   Function to call for each entity.
     * @param int<1,max>               $chunkSize  Records per chunk.
     */
    public function chunk(array $conditions, callable $callback, int $chunkSize = 1000): void
    {
        // Temporarily adjust chunk size for this operation.
        $originalChunkSize = $this->chunkSize;
        $this->chunkSize = $chunkSize;

        foreach ($this->chunkGenerator($conditions) as $entity) {
            $callback($entity);
        }

        $this->chunkSize = $originalChunkSize;
    }

    /**
     * Iterate over filtered records efficiently using a Generator.
     *
     * Memory-efficient iteration over large datasets. Records are fetched
     * in batches but yielded one at a time, keeping memory usage constant.
     *
     * Example:
     * ```php
     * foreach ($model->chunkGenerator(['status' => 'pending']) as $id => $entity) {
     *     // Process each entity
     *     // Memory stays constant regardless of total records
     * }
     * ```
     *
     * @param array<int|string, mixed> $conditions Filter conditions.
     *
     * @return Generator<int, TEntity>
     */
    public function chunkGenerator(array $conditions): Generator
    {
        $tableName = $this->getTableName();
        $pk = $this->primaryKey;
        $lastId = 0;

        [$whereClause, $values] = $this->getClauseBuilder()->buildWhere($conditions);

        while (true) {
            $sql = "SELECT * FROM `{$tableName}` WHERE {$whereClause} AND `{$pk}` > %d ORDER BY `{$pk}` ASC LIMIT %d";
            $queryValues = array_merge($values, [$lastId, $this->chunkSize]);

			// phpcs:ignore WordPress.DB.PreparedSQL
            $sql = (string) $this->wpdb->prepare($sql, ...$queryValues);

			// phpcs:ignore WordPress.DB.PreparedSQL
            $rows = $this->wpdb->get_results($sql, ARRAY_A);

            if (! is_array($rows) || $rows === []) {
                break;
            }

            /** @var list<array<string,scalar>> $rows */

            foreach ($rows as $row) {
                if (! is_array($row) || ! isset($row[$pk])) {
                    continue;
                }

                $id = (int) $row[$pk];
                $entity = $this->toEntity($row);

                if ($entity !== null) {
                    /** @var TEntity $entity */
                    yield $id => $entity;
                }

                $lastId = $id;
            }
        }
    }

    /**
     * Get the Query Builder instance.
     *
     * Lazily instantiated to ensure queryable columns are ready.
     *
     * @return ClauseBuilder
     */
    protected function getClauseBuilder(): ClauseBuilder
    {
        if ($this->clauseBuilder === null) {
            $this->clauseBuilder = new ClauseBuilder(
                $this->getQueryableSet(),
                $this->primaryKey,
            );
        }

        return $this->clauseBuilder;
    }

    /**
     * Get last changed timestamp for query cache invalidation.
     *
     * @return string Last changed timestamp.
     */
    protected function getLastChanged(): string
    {
        if ($this->cacheGroup === '') {
            return '';
        }

        /** @var string|false $lastChanged */
        $lastChanged = wp_cache_get('last_changed', $this->cacheGroup, false);

        if (! is_string($lastChanged)) {
            $lastChanged = (string) microtime(true);
            wp_cache_set('last_changed', $lastChanged, $this->cacheGroup, $this->cacheExpiration);
        }

        return $lastChanged;
    }

    /**
     * Generate a unique cache key for a query.
     *
     * @param string                   $context    Prefix context.
     * @param array<int|string, mixed> $conditions Query conditions.
     * @param array<string, string>    $orderBy    Sort order.
     * @param int|null                 $limit      Limit.
     * @param int|null                 $offset     Offset.
     *
     * @return string Unique cache key.
     */
    protected function getQueryCacheKey(
        string $context,
        array $conditions,
        array $orderBy,
        ?int $limit,
        ?int $offset = null,
    ): string {
        if ($this->cacheGroup === '') {
            return '';
        }

        $salt = $this->getLastChanged();

        $args = [
            'where' => $conditions,
            'order' => $orderBy,
            'limit' => $limit,
            'offset' => $offset,
        ];

        // Use json_encode instead of serialize for better performance.
        $hash = md5((string) wp_json_encode($args));

        return "{$context}:{$hash}:{$salt}";
    }

    /**
     * Get queryable columns as set.
     *
     * Available to child classes for validating columns in custom queries.
     *
     * @return array<string, true>
     */
    protected function getQueryableSet(): array
    {
        if ($this->queryableSet !== null) {
            return $this->queryableSet;
        }

        $columns = $this->queryable !== [] ? $this->queryable : $this->fillable;
        $columns[] = $this->primaryKey;
        $this->queryableSet = array_fill_keys($columns, true);

        return $this->queryableSet;
    }

    /**
     * Get format specifiers for wpdb.
     *
     * @param array<string, mixed> $data Data to format.
     *
     * @return array<string>
     *
     * @phpstan-return list<'%d'|'%f'|'%s'>
     */
    private function getDataFormat(array $data): array
    {
        $formats = [];
        $qb = $this->getClauseBuilder();

        foreach ($data as $value) {
            $formats[] = $qb->getPlaceholder($value);
        }

        return $formats;
    }

    /**
     * Fetch rows by IDs from database.
     *
     * @param list<int> $ids Row IDs.
     *
     * @return array<int, array<string, mixed>>
     */
    private function fetchByIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $tableName = $this->getTableName();
        $pk = $this->primaryKey;
        $results = [];

        foreach (array_chunk($ids, $this->chunkSize) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '%d'));

            // phpcs:disable WordPress.DB.PreparedSQL,WordPress.DB.PreparedSQLPlaceholders
            $sql = (string) $this->wpdb->prepare(
                "SELECT * FROM `{$tableName}` WHERE `{$pk}` IN ({$placeholders})",
                ...$chunk,
            );

            $rows = $this->wpdb->get_results($sql, ARRAY_A);
            /** @var list<array<string,scalar>> $rows */

            // phpcs:enable

            if (! is_array($rows)) {
                continue;
            }

            foreach ($rows as $row) {
                if (is_array($row) && isset($row[$pk])) {
                    $results[(int) $row[$pk]] = $row;
                }
            }
        }

        return $results;
    }

    /**
     * Sanitize and deduplicate IDs.
     *
     * @param list<int> $ids Raw IDs.
     *
     * @return list<int> Clean IDs.
     */
    private function sanitizeIds(array $ids): array
    {
        $clean = [];
        $seen = [];

        foreach ($ids as $id) {
            $id = (int) $id;

            if ($id <= 0 || isset($seen[$id])) {
                continue;
            }

            $seen[$id] = true;
            $clean[] = $id;
        }

        return $clean;
    }

    /**
     * Convert database row to entity.
     *
     * @param mixed $row Database row.
     *
     * @return TEntity|null Entity instance.
     */
    private function toEntity(mixed $row): ?AbstractEntity
    {
        if (! is_array($row) || $row === []) {
            return null;
        }

        /** @var array<string, mixed> $row */

        /** @var TEntity $entity */
        $entity = $this->entityClass::fromDatabase($row);

        return $entity;
    }

    /**
     * Filter data through fillable whitelist.
     *
     * @param array<string, mixed> $data Raw data.
     *
     * @return array<string, mixed> Filtered data.
     */
    private function filterFillable(array $data): array
    {
        if ($this->fillable === []) {
            return $data;
        }

        return array_intersect_key($data, array_flip($this->fillable));
    }

    /**
     * Get entity from cache (L2 Cache).
     *
     * @param int $id Row ID.
     *
     * @return AbstractEntity|int|null Entity, 0 (negative), or null (miss).
     */
    private function getFromCache(int $id): AbstractEntity|int|null
    {
        if ($this->cacheGroup === '') {
            return null;
        }

        $found = false;
        /** @var mixed $result */
        $result = wp_cache_get((string) $id, $this->cacheGroup, false, $found);

        if ($found === false) {
            return null;
        }

        if ($result === 0) {
            return 0;
        }

        if (is_array($result)) {
            return $this->toEntity($result);
        }

        return null;
    }

    /**
     * Get data from Query Cache (L1 Cache).
     *
     * @param string $key Cache key.
     *
     * @return mixed Cached data or null.
     */
    private function getFromQueryCache(string $key): mixed
    {
        if ($key === '' || $this->cacheGroup === '') {
            return null;
        }

        $found = false;
        $result = wp_cache_get($key, $this->cacheGroup, false, $found);

        return $found !== false ? $result : null;
    }

    /**
     * Set cache entry (L2 Cache).
     *
     * @param int   $id    Row ID.
     * @param mixed $value Row array or 0.
     */
    private function setCache(int $id, mixed $value): void
    {
        if ($this->cacheGroup === '') {
            return;
        }

        wp_cache_set((string) $id, $value, $this->cacheGroup, $this->cacheExpiration);
    }

    /**
     * Set Query Cache entry (L1 Cache).
     *
     * @param string $key   Cache key.
     * @param mixed  $value Data to cache.
     */
    private function setQueryCache(string $key, mixed $value): void
    {
        if ($key === '' || $this->cacheGroup === '') {
            return;
        }

        wp_cache_set($key, $value, $this->cacheGroup, $this->cacheExpiration);
    }

    /**
     * Clear cache for specific ID (L2 Cache).
     *
     * @param int $id Row ID.
     */
    private function clearCache(int $id): void
    {
        if ($this->cacheGroup === '') {
            return;
        }

        wp_cache_delete((string) $id, $this->cacheGroup);
    }

    /**
     * Validate configuration.
     */
    private function validateConfiguration(): void
    {
        if ($this->tableName === '') {
            throw new LogicException(static::class . ' must define $tableName.');
        }

        if ($this->primaryKey === '') {
            throw new LogicException(static::class . ' must define $primaryKey.');
        }

        if (! isset($this->entityClass) || ! class_exists($this->entityClass)) {
            throw new LogicException(static::class . ' must define valid $entityClass.');
        }

        if (! is_subclass_of($this->entityClass, AbstractEntity::class)) {
            throw new LogicException(static::class . ' $entityClass must extend AbstractEntity.');
        }
    }

    /**
     * Prevent cloning.
     */
    private function __clone(): void
    {
        // Singleton.
    }

    /**
     * Prevent unserializing.
     */
    public function __wakeup(): void
    {
        throw new LogicException('Cannot unserialize singleton.');
    }
}
