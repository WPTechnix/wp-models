<?php

declare(strict_types=1);

namespace WPTechnix\WPModels;

use BackedEnum;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use JsonException;
use JsonSerializable;
use LogicException;
use Throwable;
use UnitEnum;

/**
 * Abstract Entity Base Class.
 *
 * Implements the Active Record pattern using a "Property Bag" approach with
 * strict type casting. Entities represent individual database records and
 * provide object-oriented access to their data.
 *
 * Architecture & Timezone Strategy:
 * 1. **Storage:** Dates are stored in the database as UTC strings.
 * 2. **Hydration:** Raw DB strings are converted to `DateTimeImmutable` objects in UTC.
 * 3. **Access:** Accessing a date property via `__get` converts it to WordPress timezone.
 * 4. **Persistence:** Saving a date converts it back to UTC before serialization.
 *
 * Type Casting System:
 * - `int`: Integer casting with fallback to 0.
 * - `float`: Floating point number casting with fallback to 0.0.
 * - `decimal`: String-based number for financial precision.
 * - `string`: Text string casting with empty string fallback.
 * - `bool`: Boolean casting (stored as 0/1 in database).
 * - `datetime`: DateTimeImmutable with UTC/local timezone conversion.
 * - `json`: Array with automatic JSON encode/decode.
 * - `enum:ClassName`: PHP 8.1+ Backed Enum support.
 *
 * @template TModel of AbstractModel = AbstractModel
 */
abstract class AbstractEntity implements JsonSerializable
{
    /**
     * The Data Bag containing live entity attributes.
     *
     * Holds strictly-typed data mapped by database column name (snake_case).
     * All values are stored in their native PHP types after casting.
     *
     * @var array<string, mixed>
     */
    protected array $attributes = [];

    /**
     * Original Attributes Snapshot for dirty tracking.
     *
     * Stores the state of attributes immediately after loading from the database.
     * Used for dirty checking to generate optimized SQL UPDATE statements
     * that only include changed columns.
     *
     * @var array<string, mixed>
     */
    protected array $originalAttributes = [];

    /**
     * Attribute Type Casting Definitions.
     *
     * Maps database column names to their PHP type specifications.
     * Child classes define this to control how raw database values
     * are converted to and from PHP types.
     *
     * Supported type specifications:
     * - 'int': Integer.
     * - 'float': Floating point number.
     * - 'decimal': String-based decimal for financial precision.
     * - 'string': Text string.
     * - 'bool': Boolean (0/1 in database).
     * - 'datetime': DateTimeImmutable with timezone handling.
     * - 'json': Array with JSON serialization.
     * - 'enum:FullyQualifiedClassName': BackedEnum casting.
     *
     * @var array<non-empty-string, non-empty-string>
     */
    protected array $casts = [];

    /**
     * New Record Flag.
     *
     * Indicates if this entity is a new instance (true) that hasn't been
     * persisted to the database, or an existing record (false) that was
     * loaded from the database.
     *
     * @var bool
     */
    protected bool $isNew = true;

    /**
     * The Model Class Name.
     *
     * Must be a valid fully-qualified class string extending AbstractModel.
     * Used to access the data access layer for persistence operations.
     *
     * @var class-string<TModel>
     */
    protected static string $modelClass;

    /**
     * Cached model instance to avoid repeated singleton lookups.
     *
     * @var TModel|null
     */
    private ?AbstractModel $modelInstance = null;

    /**
     * Cached snake_case conversions to avoid repeated regex operations.
     *
     * @var array<string, string>
     */
    private static array $snakeCaseCache = [];

    /**
     * UTC timezone instance (cached for performance).
     *
     * @var DateTimeZone|null
     */
    private static ?DateTimeZone $utcTimezone = null;

    /**
     * Entity Constructor.
     *
     * Marked as final to prevent child classes from overriding initialization logic.
     * All entity instantiation should use the named constructors `create()` or
     * `fromDatabase()` to ensure proper initialization.
     */
    final private function __construct()
    {
        // Initialization logic is handled by named constructors.
        // Constructor is intentionally empty.
    }

    /**
     * Factory: Create an entity instance from a database row array.
     *
     * Used internally by Model classes when fetching records from the database.
     * Hydrates the entity with type-casted values and marks it as existing.
     *
     * @param array<string, mixed> $row The raw database row as associative array.
     *
     * @return static The hydrated, non-new entity instance.
     */
    final public static function fromDatabase(array $row): static
    {
        // Create fresh instance.
        $instance = new static();

        // Hydrate with database values, marking as existing record.
        $instance->hydrate($row, false);

        // Take snapshot for dirty tracking.
        $instance->syncOriginal();

        // @phpstan-ignore return.type
        return $instance;
    }

    /**
     * Factory: Create a new entity instance from user input.
     *
     * Used for creating new records from user input or programmatic data.
     * Optionally persists the entity immediately to the database.
     *
     * @param array<string, mixed> $data Associative array of attribute data.
     * @param bool                 $save If true, immediately persists to database.
     *
     * @return static The new entity instance (saved or unsaved based on $save).
     */
    final public static function create(array $data, bool $save = true): static
    {
        // Create fresh instance.
        $instance = new static();

        // Mark as new record.
        $instance->isNew = true;

        // Fill attributes from input data.
        $instance->fill($data);

        // Optionally persist to database.
        if ($save) {
            $instance->save();
        }

        // @phpstan-ignore return.type
        return $instance;
    }

    /**
     * Convert the entity to an array for database persistence.
     *
     * Serializes all typed values (objects, enums, etc.) back to scalar
     * database strings suitable for SQL INSERT/UPDATE statements.
     *
     * @return array<string, mixed> Associative array of column => value pairs.
     */
    public function toArray(): array
    {
        $data = [];
        $pk = $this->getModel()->getPrimaryKey();

        // Include Primary Key if present.
        if (isset($this->attributes[$pk])) {
            $data[$pk] = $this->attributes[$pk];
        }

        // Serialize all attributes defined in casts.
        foreach ($this->casts as $key => $type) {
            // Skip attributes not present in the data bag.
            if (! array_key_exists($key, $this->attributes)) {
                continue;
            }

            // Serialize typed value to database scalar.
            $data[$key] = $this->serializeAttribute($key, $this->attributes[$key]);
        }

        return $data;
    }

    /**
     * Implement JsonSerializable interface.
     *
     * Returns data suitable for JSON encoding. Dates are converted to
     * ISO 8601 format strings in the local WordPress timezone.
     *
     * @return array<string, mixed> JSON-serializable representation of entity.
     */
    public function jsonSerialize(): array
    {
        $data = [];
        $pk = $this->getModel()->getPrimaryKey();

        // Include Primary Key if present.
        if (isset($this->attributes[$pk])) {
            $data[$pk] = $this->attributes[$pk];
        }

        // Convert all attributes for JSON output.
        foreach ($this->casts as $key => $type) {
            // Skip attributes not present in the data bag.
            if (! array_key_exists($key, $this->attributes)) {
                continue;
            }

            $value = $this->attributes[$key];

            // Handle special types for JSON output.
            if ($value instanceof DateTimeInterface) {
                // Convert to local timezone and ISO 8601 format.
                $data[$key] = $this->localizeDate(
                    DateTimeImmutable::createFromInterface($value),
                )->format('c');
            } elseif ($value instanceof BackedEnum) {
                // Use enum backing value.
                $data[$key] = $value->value;
            } elseif ($value instanceof UnitEnum) {
                // Use enum name for unit enums.
                $data[$key] = $value->name;
            } else {
                // Pass through other values.
                $data[$key] = $value;
            }
        }

        return $data;
    }

    /**
     * Get the Model instance responsible for this Entity.
     *
     * Resolves the singleton Model instance using the static `$modelClass` property.
     * The Model provides data access layer functionality for persistence operations.
     *
     * @return TModel The singleton Model instance.
     */
    public function getModel(): AbstractModel
    {
        // Return cached instance if available.
        if ($this->modelInstance !== null) {
            return $this->modelInstance;
        }

        // Validate model class is defined.
        if (! isset(static::$modelClass) || static::$modelClass === '') {
            throw new LogicException(
                sprintf('Entity %s must define a protected static $modelClass property.', static::class),
            );
        }

        // Validate model class exists.
        if (! class_exists(static::$modelClass)) {
            throw new LogicException(
                sprintf(
                    'Entity %s has invalid $modelClass: %s does not exist.',
                    static::class,
                    static::$modelClass,
                ),
            );
        }

        // Get and cache singleton instance.
        $instance = static::$modelClass::instance();
        $this->modelInstance = $instance;

        return $this->modelInstance;
    }

    /**
     * Get the Primary Key value.
     *
     * Returns the current primary key value, typically an auto-incremented integer ID.
     * Returns null for unsaved entities that don't yet have a database ID.
     *
     * @return int|null The primary key ID, or null if not set.
     */
    public function getPkValue(): ?int
    {
        // Get primary key column name from model.
        $pkName = $this->getModel()->getPrimaryKey();

        // Retrieve value from attributes.
        $val = $this->attributes[$pkName] ?? null;

        // Return as integer if numeric, null otherwise.
        return is_numeric($val) ? (int) $val : null;
    }

    /**
     * Set the Primary Key value.
     *
     * Updates the primary key attribute. Used internally after database insertion
     * to populate the auto-generated ID. Can also unset the PK by passing null.
     *
     * @param int|null $value The ID value to set, or null to unset.
     */
    public function setPkValue(?int $value): void
    {
        // Get primary key column name from model.
        $pkName = $this->getModel()->getPrimaryKey();

        // Handle null value.
        if ($value === null) {
            unset($this->attributes[$pkName]);

            return;
        }

        // Set integer value.
        $this->attributes[$pkName] = $value;
    }

    /**
     * Check if the entity exists in the database.
     *
     * Determines if this entity has been persisted. Optionally performs
     * a database query to verify the record still exists.
     *
     * @param bool $forceCheckInDb If true, performs actual database query.
     *
     * @return bool True if the record exists in the database.
     */
    public function exists(bool $forceCheckInDb = false): bool
    {
        // Get current primary key value.
        $id = $this->getPkValue();

        // Check if we have a valid ID.
        $hasId = ($id !== null && $id > 0);

        // No ID means definitely doesn't exist.
        if (! $hasId) {
            return false;
        }

        // Optionally verify via database query.
        if ($forceCheckInDb) {
            return $this->getModel()->exists($id);
        }

        // Has valid ID, assume exists.
        return true;
    }

    /**
     * Reload the entity attributes from the database.
     *
     * Fetches fresh data from the database and updates the current instance.
     * Useful after external modifications or to discard local changes.
     *
     * @return bool True on success, false if record no longer exists.
     */
    public function refresh(): bool
    {
        // Get current primary key value.
        $id = $this->getPkValue();

        // Cannot refresh without an ID.
        if ($id === null) {
            return false;
        }

        // Fetch fresh entity from database.
        $fresh = $this->getModel()->find($id);

        // Check if record still exists.
        if ($fresh === null) {
            return false;
        }

        // Copy attributes from fresh instance.
        $this->attributes = $fresh->attributes;

        // Mark as existing record.
        $this->isNew = false;

        // Resync original attributes for dirty tracking.
        $this->syncOriginal();

        return true;
    }

    /**
     * Save the entity to the database.
     *
     * Persists changes by executing INSERT (for new records) or UPDATE
     * (for existing records). Triggers lifecycle hooks before and after.
     *
     * For updates, only changed (dirty) attributes are included in the
     * SQL statement for optimal performance.
     *
     * @return bool True on successful persistence, false on failure.
     */
    public function save(): bool
    {
        // Trigger pre-save lifecycle hook.
        $this->beforeSave();

        $model = $this->getModel();
        $success = false;

        // Branch: Handle creation of new records.
        if ($this->isNew) {
            // Attempt database insertion.
            $id = $model->create($this->toArray());

            // Check for successful insertion.
            if ($id !== false && $id > 0) {
                // Update entity with generated ID.
                $this->setPkValue($id);

                // Mark as existing record.
                $this->isNew = false;

                // Sync original attributes for future dirty tracking.
                $this->syncOriginal();

                $success = true;
            }
        } elseif ($this->isDirty()) {
            // Branch: Handle update of existing dirty records.
            $pk = $this->getPkValue();

            // Ensure we have a valid primary key.
            if ($pk !== null && $pk > 0) {
                // Get only changed attributes.
                $dirtyData = $this->getDirtyData();

                // Handle edge case: isDirty() returned true but no data.
                if ($dirtyData === []) {
                    $success = true;
                } else {
                    // Serialize dirty attributes for SQL.
                    $serializedDirty = [];

                    foreach ($dirtyData as $key => $val) {
                        $serializedDirty[$key] = $this->serializeAttribute($key, $val);
                    }

                    // Attempt database update.
                    if ($model->update($pk, $serializedDirty)) {
                        // Sync original attributes for future dirty tracking.
                        $this->syncOriginal();

                        $success = true;
                    }
                }
            }
        } else {
            // Branch: Not dirty and not new = nothing to save = success.
            $success = true;
        }

        // Trigger post-save lifecycle hook on success.
        if ($success) {
            $this->afterSave();
        }

        return $success;
    }

    /**
     * Delete the entity from the database.
     *
     * Permanently removes the record. After deletion, the entity is reset
     * to a "new" state with no primary key.
     *
     * @return bool True on successful deletion, false on failure.
     */
    public function delete(): bool
    {
        // Cannot delete new/unsaved entities.
        if ($this->isNew) {
            return false;
        }

        // Get current primary key value.
        $pk = $this->getPkValue();

        // Cannot delete without a valid primary key.
        if ($pk === null || $pk <= 0) {
            return false;
        }

        // Attempt database deletion.
        if ($this->getModel()->delete($pk)) {
            // Reset entity state to new/unsaved.
            $this->setPkValue(null);
            $this->isNew = true;
            $this->originalAttributes = [];

            return true;
        }

        return false;
    }

    /**
     * Check if this is a new, unsaved record.
     *
     * New records have not been persisted to the database. They will be
     * Inserted on save() rather than Updated.
     *
     * @return bool True if the entity is new (not yet persisted).
     */
    public function isNew(): bool
    {
        return $this->isNew;
    }

    /**
     * Check if the entity has pending unsaved changes.
     *
     * Compares current attributes against the original snapshot taken
     * after database load to detect modifications.
     *
     * @return bool True if any attributes have been modified.
     */
    public function isDirty(): bool
    {
        return $this->getDirtyData() !== [];
    }

    /**
     * Check if a specific attribute has been modified.
     *
     * Determines if a particular attribute differs from its original value.
     *
     * @param string $key The attribute key to check (snake_case).
     *
     * @return bool True if the attribute has been modified.
     */
    public function isAttributeDirty(string $key): bool
    {
        // Attribute cannot be dirty if not present.
        if (! array_key_exists($key, $this->attributes)) {
            return false;
        }

        // New attribute (not in original) is dirty.
        if (! array_key_exists($key, $this->originalAttributes)) {
            return true;
        }

        // Compare current and original values.
        return $this->hasSemanticChange($this->originalAttributes[$key], $this->attributes[$key]);
    }

    /**
     * Get the original value of an attribute before modification.
     *
     * Returns the value as it was when loaded from the database or
     * after the last successful save.
     *
     * @param string $key The attribute key (snake_case).
     *
     * @return mixed The original value, or null if not present.
     */
    public function getOriginal(string $key): mixed
    {
        return $this->originalAttributes[$key] ?? null;
    }

    /**
     * Get all raw attributes.
     *
     * Returns the internal attributes array without any transformation.
     * Useful for debugging or bulk attribute access.
     *
     * @return array<string, mixed> All current attribute values.
     */
    public function getAttributes(): array
    {
        return $this->attributes;
    }

    /**
     * Get a specific raw attribute value.
     *
     * Returns the value directly from the attributes array without
     * type transformation or accessor methods.
     *
     * @param string $key The attribute key (snake_case).
     *
     * @return mixed The raw attribute value, or null if not present.
     */
    public function getAttribute(string $key): mixed
    {
        return $this->attributes[$key] ?? null;
    }

    /**
     * Set a specific raw attribute value.
     *
     * Updates the attributes array directly, applying type casting
     * based on the $casts configuration.
     *
     * @param string $key   The attribute key (snake_case).
     * @param mixed  $value The value to set.
     */
    public function setAttribute(string $key, mixed $value): void
    {
        // Apply casting if defined for this key.
        if (array_key_exists($key, $this->casts)) {
            $this->attributes[$key] = $this->castAttribute($key, $value);

            return;
        }

        // Check if key is the primary key.
        $pkName = $this->getModel()->getPrimaryKey();

        if ($key === $pkName) {
            $this->attributes[$key] = is_numeric($value) ? (int) $value : null;

            return;
        }

        // Store raw value for uncasted attributes.
        $this->attributes[$key] = $value;
    }

    /**
     * Lifecycle Hook: Called before creation or update.
     *
     * Override in child classes to perform pre-save operations like
     * setting timestamps, validation, or data transformation.
     */
    protected function beforeSave(): void
    {
        // Override in child class for pre-save logic.
    }

    /**
     * Lifecycle Hook: Called after successful creation or update.
     *
     * Override in child classes to perform post-save operations like
     * cache invalidation, event dispatching, or related record updates.
     */
    protected function afterSave(): void
    {
        // Override in child class for post-save logic.
    }

    /**
     * Hydrate the entity from a raw database array.
     *
     * Populates the attributes array by casting each value according
     * to the type definitions in $casts.
     *
     * @param array<string, mixed> $row   Database row as associative array.
     * @param bool                 $isNew Whether this is a new record.
     */
    protected function hydrate(array $row, bool $isNew = true): void
    {
        // Set new record flag.
        $this->isNew = $isNew;

        // Pre-allocate attributes array for better memory efficiency.
        $this->attributes = [];

        // Process all cast definitions.
        foreach ($this->casts as $key => $type) {
            // Cast value if present in row, null otherwise.
            $this->attributes[$key] = array_key_exists($key, $row)
                ? $this->castAttribute($key, $row[$key])
                : null;
        }

        // Ensure Primary Key is set if present in row.
        $pk = $this->getModel()->getPrimaryKey();

        if (isset($row[$pk]) && is_numeric($row[$pk])) {
            $this->attributes[$pk] = (int) $row[$pk];
        }
    }

    /**
     * Fill the entity with user input data.
     *
     * Processes input data through the magic setter, which handles
     * camelCase to snake_case conversion and type casting.
     *
     * @param array<string, mixed> $data Associative array of input data.
     */
    protected function fill(array $data): void
    {
        foreach ($data as $key => $value) {
            // Use magic setter for proper handling.
            $this->__set($key, $value);
        }
    }

    /**
     * Sync original attributes with current attributes.
     *
     * Creates a snapshot of current attributes for dirty tracking.
     * Called after database operations to establish a new baseline.
     * Uses selective cloning for memory efficiency.
     */
    protected function syncOriginal(): void
    {
        // Build snapshot with selective cloning.

        $snapshot = array_map(fn ($val) => $this->cloneValueForSnapshot($val), $this->attributes);

        // Store snapshot.
        $this->originalAttributes = $snapshot;
    }

    /**
     * Cast a raw value to the internal type defined in $casts.
     *
     * Converts database values to their appropriate PHP types based
     * on the type specification string.
     *
     * @param string $key   The attribute key to look up cast type.
     * @param mixed  $value The raw value from database or user input.
     *
     * @return mixed The strictly-typed value.
     */
    protected function castAttribute(string $key, mixed $value): mixed
    {
        // Get type specification, default to string.
        $type = $this->casts[$key] ?? 'string';

        // Allow NULL values to pass through for nullable columns.
        if ($value === null) {
            return null;
        }

        // Handle Enum casting with special prefix syntax.
        if (str_starts_with($type, 'enum:')) {
            // Extract enum class name after 'enum:' prefix.
            return $this->castToEnum(substr($type, 5), $value);
        }

        // Handle standard types using match expression.
        return match ($type) {
            'int' => is_numeric($value) ? (int) $value : 0,
            'decimal' => is_scalar($value) ? (string) $value : '0.00',
            'float' => is_numeric($value) ? (float) $value : 0.0,
            'bool' => (bool) $value,
            'datetime' => $this->castToDateTime($value),
            'json' => $this->castToJson($value),
            default => is_scalar($value) ? (string) $value : '',
        };
    }

    /**
     * Serialize an internal value to a database-safe scalar.
     *
     * Converts PHP objects and complex types back to scalar values
     * suitable for SQL INSERT/UPDATE statements.
     *
     * @param string $key   The attribute key to look up cast type.
     * @param mixed  $value The typed PHP value to serialize.
     *
     * @return mixed The scalar database value.
     */
    protected function serializeAttribute(string $key, mixed $value): mixed
    {
        // Get type specification.
        $type = $this->casts[$key] ?? 'string';

        // NULL passes through unchanged.
        if ($value === null) {
            return null;
        }

        // Handle Enum serialization.
        if (str_starts_with($type, 'enum:')) {
            // BackedEnums serialize to their backing value.
            if ($value instanceof BackedEnum) {
                return $value->value;
            }

            // UnitEnums serialize to their name.
            if ($value instanceof UnitEnum) {
                return $value->name;
            }

            // Pass through raw values.
            return $value;
        }

        // Handle standard types using match expression.
        return match ($type) {
            'datetime' => $value instanceof DateTimeInterface
                ? $this->serializeDate($value)
                : null,
            'json' => $this->serializeJson($value),
            'bool' => (bool) $value ? 1 : 0,
            'decimal' => is_scalar($value) ? (string) $value : '',
            default => $value,
        };
    }

    /**
     * Cast value to a BackedEnum safely.
     *
     * Handles conversion from raw values to PHP 8.1+ BackedEnum instances
     * with proper error handling for invalid values.
     *
     * @param string $enumClass The fully-qualified enum class name.
     * @param mixed  $value     The raw backing value to convert.
     *
     * @return BackedEnum|null The enum instance, or null if invalid.
     */
    protected function castToEnum(string $enumClass, mixed $value): ?BackedEnum
    {
        // Return value directly if already correct enum type.
        if ($value instanceof $enumClass && $value instanceof BackedEnum) {
            return $value;
        }

        // Verify enum class exists.

        /** @var class-string $enumClass */
        if (! enum_exists($enumClass)) {
            return null;
        }

        // Must be int or string for BackedEnum::tryFrom().
        if (! is_int($value) && ! is_string($value)) {
            return null;
        }

        // Verify class is a BackedEnum.
        if (! is_subclass_of($enumClass, BackedEnum::class)) {
            return null;
        }

        // Use tryFrom for safe conversion.
        return $enumClass::tryFrom($value);
    }

    /**
     * Convert value to a DateTimeImmutable object in UTC.
     *
     * Handles various date input formats and normalizes to UTC timezone.
     * Supports DateTimeInterface objects, timestamp strings, and standard formats.
     *
     * @param mixed $value The input value (string, DateTime, or other).
     *
     * @return DateTimeImmutable|null The UTC DateTimeImmutable, or null on failure.
     */
    protected function castToDateTime(mixed $value): ?DateTimeImmutable
    {
        // Get cached UTC timezone.
        $utc = $this->getUtcTimezone();

        // Handle DateTimeImmutable input - convert to UTC.
        if ($value instanceof DateTimeImmutable) {
            return $value->setTimezone($utc);
        }

        // Handle other DateTimeInterface implementations.
        if ($value instanceof DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($value)->setTimezone($utc);
        }

        // Must be a non-empty string at this point.
        if (! is_string($value) || $value === '') {
            return null;
        }

        // Reject MySQL zero dates.
        if (str_starts_with($value, '0000-00-00')) {
            return null;
        }

        // Attempt to parse string to DateTime.
        try {
            return new DateTimeImmutable($value, $utc);
        } catch (Throwable) {
            // Return null on any parsing failure.
            return null;
        }
    }

    /**
     * Convert value to an array from JSON string.
     *
     * Handles JSON string decoding with proper error handling.
     * Returns empty array on invalid input.
     *
     * @param mixed $value The input value (JSON string or array).
     *
     * @return array<array-key, mixed> The decoded array.
     */
    protected function castToJson(mixed $value): array
    {
        // Return arrays directly.
        if (is_array($value)) {
            return $value;
        }

        // Convert objects to arrays.
        if (is_object($value)) {
            return (array) $value;
        }

        // Must be a non-empty string at this point.
        if (! is_string($value) || $value === '') {
            return [];
        }

        // Attempt JSON decoding.
        try {
            $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);

            // Ensure result is an array.
            return is_array($decoded) ? $decoded : [];
        } catch (JsonException) {
            // Return empty array on JSON errors.
            return [];
        }
    }

    /**
     * Serialize a DateTime object to a UTC database string.
     *
     * Converts to UTC timezone before formatting to ensure consistent
     * storage regardless of the input timezone.
     *
     * @param DateTimeInterface $date The date object to serialize.
     *
     * @return string The formatted date string in 'Y-m-d H:i:s' format.
     */
    protected function serializeDate(DateTimeInterface $date): string
    {
        // Clone and convert to UTC before formatting.
        return DateTimeImmutable::createFromInterface($date)
                                ->setTimezone($this->getUtcTimezone())
                                ->format('Y-m-d H:i:s');
    }

    /**
     * Serialize data to a JSON string for database storage.
     *
     * Uses WordPress's wp_json_encode for consistent encoding
     * with proper error handling.
     *
     * @param mixed $value The data to encode.
     *
     * @return string JSON string, or '{}' on encoding failure.
     */
    protected function serializeJson(mixed $value): string
    {
        // Use WordPress JSON encoder.
        $json = wp_json_encode($value);

        // Fallback to empty object on failure.
        if (! is_string($json)) {
            return '{}';
        }

        return $json;
    }

    /**
     * Localize a UTC DateTime object to the WordPress timezone.
     *
     * Converts dates from their UTC storage format to the timezone
     * configured in WordPress settings for display purposes.
     *
     * @param DateTimeImmutable $date The UTC date to localize.
     *
     * @return DateTimeImmutable The date in WordPress local timezone.
     */
    protected function localizeDate(DateTimeImmutable $date): DateTimeImmutable
    {
        try {
            // Use WordPress timezone function.
            return $date->setTimezone(wp_timezone());
        } catch (Throwable) {
            // Return original on timezone errors.
            return $date;
        }
    }

    /**
     * Get the attributes that have changed since loading.
     *
     * Compares current attributes against original snapshot to identify
     * which columns need to be included in UPDATE statements.
     *
     * @return array<string, mixed> Associative array of changed attributes.
     */
    protected function getDirtyData(): array
    {
        $dirty = [];

        foreach ($this->attributes as $key => $value) {
            // Skip primary key - it's not updatable.
            if ($key === $this->getModel()->getPrimaryKey()) {
                continue;
            }

            // New attribute (not in original) is dirty.
            if (! array_key_exists($key, $this->originalAttributes)) {
                $dirty[$key] = $value;

                continue;
            }

            // Get original value for comparison.
            $original = $this->originalAttributes[$key];

            // Skip if identical by reference.
            if ($value === $original) {
                continue;
            }

            // Check for semantic change (handles object comparison).
            if ($this->hasSemanticChange($original, $value)) {
                $dirty[$key] = $value;
            }
        }

        return $dirty;
    }

    /**
     * Determine if two values have semantically changed.
     *
     * Performs type-aware comparison to detect actual data changes.
     * Handles special cases for DateTime and array comparisons.
     *
     * @param mixed $original The original value from snapshot.
     * @param mixed $current  The current value being compared.
     *
     * @return bool True if values are semantically different.
     */
    protected function hasSemanticChange(mixed $original, mixed $current): bool
    {
        // Handle null comparisons.
        if ($original === null && $current === null) {
            return false;
        }

        if ($original === null || $current === null) {
            return true;
        }

        // DateTime comparison by timestamp.
        if ($original instanceof DateTimeInterface && $current instanceof DateTimeInterface) {
            return $original->getTimestamp() !== $current->getTimestamp();
        }

        // Array comparison by value (recursive).
        if (is_array($original) && is_array($current)) {
            return $original !== $current;
        }

        // Enum comparison by value/name.
        if ($original instanceof BackedEnum && $current instanceof BackedEnum) {
            return $original->value !== $current->value;
        }

        if ($original instanceof UnitEnum && $current instanceof UnitEnum) {
            return $original->name !== $current->name;
        }

        // Scalar comparison.
        if (is_scalar($original) && is_scalar($current)) {
            return $original !== $current;
        }

        // Default: Assume changed if we got here (different types or complex objects).
        return true;
    }

    /**
     * Convert camelCase property name to snake_case column name.
     *
     * Enables property access using camelCase while storing in snake_case
     * as per database column naming conventions. Results are cached for performance.
     *
     * @param string $camelCase Property name in camelCase format.
     *
     * @return string Column name in snake_case format.
     */
    protected function camelToSnake(string $camelCase): string
    {
        // Check cache first.
        if (isset(self::$snakeCaseCache[$camelCase])) {
            return self::$snakeCaseCache[$camelCase];
        }

        // Use regex to insert underscores before uppercase letters.
        $snakeCase = strtolower(
            (string) preg_replace('/(?<!^)[A-Z]/', '_$0', $camelCase),
        );

        // Cache for future lookups.
        self::$snakeCaseCache[$camelCase] = $snakeCase;

        return $snakeCase;
    }

    /**
     * Clone a value for snapshot storage.
     *
     * Determines if a value needs to be cloned or can be stored directly.
     * Immutable types are stored directly, mutable objects are cloned.
     *
     * @param mixed $val The value to potentially clone.
     *
     * @return mixed The value or its clone.
     */
    private function cloneValueForSnapshot(mixed $val): mixed
    {
        // Non-objects don't need cloning.
        if (! is_object($val)) {
            return $val;
        }

        // Enums are immutable and should not be cloned.
        if ($val instanceof UnitEnum) {
            return $val;
        }

        // DateTimeImmutable is immutable - no need to clone.
        if ($val instanceof DateTimeImmutable) {
            return $val;
        }

        // Clone mutable objects to ensure value-copy semantics.
        try {
            return clone $val;
            // @phpstan-ignore-next-line -- Clone may throw exception.
        } catch (Throwable) {
            // Return original if cloning fails.
            return $val;
        }
    }

    /**
     * Get cached UTC timezone instance.
     *
     * @return DateTimeZone The UTC timezone.
     */
    private function getUtcTimezone(): DateTimeZone
    {
        if (self::$utcTimezone === null) {
            self::$utcTimezone = new DateTimeZone('UTC');
        }

        return self::$utcTimezone;
    }

    /**
     * Deep clone support for entity copying.
     *
     * Creates a true copy of the entity by cloning mutable objects
     * and resetting identity-related state.
     */
    public function __clone(): void
    {
        // Reset to new/unsaved state.
        $this->isNew = true;
        $this->setPkValue(null);
        $this->originalAttributes = [];

        // Clear cached model instance (will be re-fetched on demand).
        $this->modelInstance = null;

        // Clone mutable objects in attributes.
        foreach ($this->attributes as $key => $value) {
            // Skip non-objects.
            if (! is_object($value)) {
                continue;
            }

            // Skip immutable enums.
            if ($value instanceof UnitEnum) {
                continue;
            }

            // Skip DateTimeImmutable (immutable).
            if ($value instanceof DateTimeImmutable) {
                continue;
            }

            // Clone mutable objects.
            try {
                $this->attributes[$key] = clone $value;
                // @phpstan-ignore-next-line -- clone may throw exception.
            } catch (Throwable) {
                // Skip objects that cannot be cloned.
                continue;
            }
        }
    }

    /**
     * Magic Getter for property access.
     *
     * Resolution order:
     * 1. Accessor methods (getFooAttribute).
     * 2. Mapped attributes (camelCase to snake_case conversion).
     * 3. DateTime localization to WordPress timezone.
     *
     * @param string $key The property name in camelCase.
     *
     * @return mixed The attribute value, or null if not found.
     */
    public function __get(string $key): mixed
    {
        // Step 1: Check for accessor method.
        $accessor = 'get' . ucfirst($key) . 'Attribute';

        if (is_callable([$this, $accessor])) {
            /** @phpstan-ignore method.dynamicName */
            return $this->$accessor();
        }

        // Step 2: Convert to snake_case for attribute lookup.
        $snakeKey = $this->camelToSnake($key);

        // Check if key is in casts.
        if (! array_key_exists($snakeKey, $this->casts)) {
            // Allow Primary Key access even if not in casts.
            if ($snakeKey === $this->getModel()->getPrimaryKey()) {
                return $this->attributes[$snakeKey] ?? null;
            }

            return null;
        }

        // Get raw value.
        $value = $this->attributes[$snakeKey] ?? null;

        // Step 3: Localize DateTime objects to WordPress timezone.
        if ($value instanceof DateTimeImmutable) {
            return $this->localizeDate($value);
        }

        return $value;
    }

    /**
     * Magic Setter for property assignment.
     *
     * Handles camelCase to snake_case conversion and applies type casting
     * based on the $casts configuration.
     *
     * @param string $key   The property name in camelCase.
     * @param mixed  $value The value to set.
     */
    public function __set(string $key, mixed $value): void
    {
        // Convert to snake_case for storage.
        $snakeKey = $this->camelToSnake($key);

        // Check if key is in casts.
        if (! array_key_exists($snakeKey, $this->casts)) {
            // Allow Primary Key assignment even if not in casts.
            if ($snakeKey === $this->getModel()->getPrimaryKey()) {
                $this->attributes[$snakeKey] = is_numeric($value) ? (int) $value : null;

                return;
            }

            // Silently ignore unknown attributes.
            return;
        }

        // Apply casting and store.
        $this->attributes[$snakeKey] = $this->castAttribute($snakeKey, $value);
    }

    /**
     * Magic Isset for property existence check.
     *
     * Checks if a property is set, supporting both accessor methods
     * and direct attribute access.
     *
     * @param string $key The property name in camelCase.
     *
     * @return bool True if the property is set.
     */
    public function __isset(string $key): bool
    {
        // Check for accessor method.
        $accessor = 'get' . ucfirst($key) . 'Attribute';

        if (method_exists($this, $accessor)) {
            return true;
        }

        // Check attribute exists.
        $snakeKey = $this->camelToSnake($key);

        return isset($this->attributes[$snakeKey]);
    }

    /**
     * Magic Unset for property removal.
     *
     * Removes an attribute from the entity's data bag.
     *
     * @param string $key The property name in camelCase.
     */
    public function __unset(string $key): void
    {
        $snakeKey = $this->camelToSnake($key);

        unset($this->attributes[$snakeKey]);
    }
}
