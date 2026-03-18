<?php

declare(strict_types=1);

namespace WPTechnix\WPModels;

use InvalidArgumentException;

/**
 * SQL Conditions Compiler.
 *
 * A specialized utility class responsible for transforming array-based query conditions
 * into secure SQL clauses and value arrays suitable for `wpdb::prepare()`.
 *
 * Design Philosophy:
 * - **Security First**: All columns are validated against an allow-list.
 * - **Type Safety**: Placeholders (%d, %f, %s) are determined dynamically based on value types.
 * - **Flexibility**: Supports nested AND/OR groups, mixed-case operators, and sloppy input.
 * - **Robustness**: Auto-corrects common mistakes (e.g., using '=' with an array value).
 */
final class ClauseBuilder
{
    /**
     * Allowed SQL comparison operators.
     */
    private const ALLOWED_OPERATORS = [
        '=' => true,
        '!=' => true,
        '<>' => true,
        '>' => true,
        '<' => true,
        '>=' => true,
        '<=' => true,
        'LIKE' => true,
        'NOT LIKE' => true,
        'IN' => true,
        'NOT IN' => true,
        'BETWEEN' => true,
        'NOT BETWEEN' => true,
    ];

    /**
     * Allowed relation types for grouped conditions.
     */
    private const ALLOWED_RELATIONS = [
        'AND' => true,
        'OR' => true,
    ];

    /**
     * Set of columns allowed for querying.
     *
     * @var array<string, true>
     */
    private array $allowedColumns;

    /**
     * The primary key column name (used for default sorting).
     *
     * @var string
     */
    private string $primaryKey;

    /**
     * Constructor.
     *
     * @param array<string, true> $allowedColumns Map of allowed column names (Column => true).
     * @param string              $primaryKey     Primary key column name.
     */
    public function __construct(array $allowedColumns, string $primaryKey = 'id')
    {
        $this->allowedColumns = $allowedColumns;
        $this->primaryKey = $primaryKey;
    }

    /**
     * Compile conditions into a WHERE clause.
     *
     * @param array<int|string, mixed> $conditions Query conditions.
     *
     * @return array{string, list<mixed>} [SQL string, Values array].
     *
     * @throws InvalidArgumentException If columns are invalid or structure is malformed.
     */
    public function buildWhere(array $conditions): array
    {
        if ($conditions === []) {
            return ['1=1', []];
        }

        $normalized = $this->normalizeConditions($conditions);

        return $this->compileConditions($normalized);
    }

    /**
     * Build an ORDER BY clause.
     *
     * @param array<string, string> $orderBy Map of Column => Direction (e.g., ['id' => 'DESC']).
     *
     * @return string SQL ORDER BY clause.
     *
     * @throws InvalidArgumentException If a column is not allowed.
     */
    public function buildOrderBy(array $orderBy): string
    {
        if ($orderBy === []) {
            return "`{$this->primaryKey}` DESC";
        }

        $parts = [];

        foreach ($orderBy as $column => $direction) {
            if (! isset($this->allowedColumns[$column])) {
                throw new InvalidArgumentException("Column not available for sorting: {$column}");
            }

            $dir = strtoupper((string) $direction) === 'ASC' ? 'ASC' : 'DESC';
            $parts[] = "`{$column}` {$dir}";
        }

        return implode(', ', $parts);
    }

    /**
     * Helper: Determine the appropriate wpdb placeholder for a value.
     *
     * @param mixed $value The value to inspect.
     *
     * @return string %d' for int/bool, '%f' for float, '%s' for others.
     *
     * @phpstan-return '%d'|'%f'|'%s'
     */
    public function getPlaceholder(mixed $value): string
    {
        if (is_int($value) || is_bool($value)) {
            return '%d';
        }

        if (is_float($value)) {
            return '%f';
        }

        return '%s';
    }

    /**
     * Helper: Convert flat attributes array to standard conditions format.
     *
     * Useful for `firstOrCreate` style methods where input is simplified key-value pairs.
     *
     * @param array<string, mixed> $attributes Key-value pairs (Col => Val).
     *
     * @return list<array{column: string, value: mixed}>
     */
    public function attributesToConditions(array $attributes): array
    {
        $conditions = [];

        foreach ($attributes as $column => $value) {
            $conditions[] = ['column' => $column, 'value' => $value];
        }

        return $conditions;
    }

    /**
     * Normalize raw conditions into a structured recursive array.
     *
     * Handles nested logic groups, case insensitivity on relations, and structural validation.
     *
     * @param array<int|string, mixed> $conditions Raw conditions.
     *
     * @return array{relation: string, clauses: list<mixed>}
     *
     * @throws InvalidArgumentException If relation type is invalid.
     */
    private function normalizeConditions(array $conditions): array
    {
        $relation = 'AND';

        // Check if explicit relation is defined
        if (isset($conditions['relation'])) {
            $relation = is_string($conditions['relation'])
                ? strtoupper(trim($conditions['relation']))
                : '';

            if (! isset(self::ALLOWED_RELATIONS[$relation])) {
                throw new InvalidArgumentException("Invalid relation: {$relation}");
            }

            unset($conditions['relation']);
        }

        // Detect if this is a single condition like ['column' => 'a', 'value' => 1]
        if ($this->isSingleCondition($conditions)) {
            return [
                'relation' => $relation,
                'clauses' => [$this->validateCondition($conditions)],
            ];
        }

        $clauses = [];

        foreach ($conditions as $condition) {
            if (! is_array($condition)) {
                throw new InvalidArgumentException('Each condition must be an array.');
            }

            // Recursive check: is this array a group or a specific condition?
            if ($this->isNestedGroup($condition)) {
                $clauses[] = $this->normalizeConditions($condition);

                continue;
            }

            /** @var array<string,mixed> $condition */

            $clauses[] = $this->validateCondition($condition);
        }

        return [
            'relation' => $relation,
            'clauses' => $clauses,
        ];
    }

    /**
     * Determine if an array represents a single condition (Col/Val pair).
     *
     * Checks for the existence of required keys to differentiate between a condition
     * and a list of conditions.
     *
     * @param array<int|string, mixed> $condition The array to inspect.
     *
     * @return bool True if it looks like a single condition.
     */
    private function isSingleCondition(array $condition): bool
    {
        return isset($condition['column']) && array_key_exists('value', $condition);
    }

    /**
     * Determine if an array represents a nested group of conditions.
     *
     * A group implies either an explicit 'relation' key OR a list of indexed arrays.
     *
     * @param array<int|string, mixed> $condition The array to inspect.
     *
     * @return bool True if it contains nested logic.
     */
    private function isNestedGroup(array $condition): bool
    {
        if (isset($condition['relation'])) {
            return true;
        }

        // Check if keys are numeric and values are arrays (implicit group)
        foreach ($condition as $key => $value) {
            if (is_int($key) && is_array($value)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Validate a single condition and normalize operators.
     *
     * Ensures column existence, standardizes operators, and auto-corrects
     * array value mismatches (e.g., swapping '=' for 'IN').
     *
     * @param array<int|string, mixed> $condition Raw single condition.
     *
     * @return array{column: string, operator: string, value: mixed}
     *
     * @throws InvalidArgumentException If column is missing, not allowed, or operator is invalid.
     */
    private function validateCondition(array $condition): array
    {
        if (! isset($condition['column']) || ! is_string($condition['column']) || $condition['column'] === '') {
            throw new InvalidArgumentException('Condition must have a "column" key.');
        }

        $column = $condition['column'];

        if (! isset($this->allowedColumns[$column])) {
            throw new InvalidArgumentException("Column not queryable: {$column}");
        }

        if (! array_key_exists('value', $condition)) {
            throw new InvalidArgumentException('Condition must have a "value" key.');
        }

        $value = $condition['value'];
        $operator = '=';

        if (isset($condition['operator']) && is_string($condition['operator'])) {
            $rawOp = strtoupper(trim($condition['operator']));

            if (!isset(self::ALLOWED_OPERATORS[$rawOp])) {
                throw new InvalidArgumentException("Invalid operator: {$rawOp}");
            }

            $operator = $rawOp;
        }

        // Auto-correction: Arrays must use IN/NOT IN/BETWEEN.
        // If a user tries `id = [1,2]`, we convert to `id IN (1,2)`.
        if (is_array($value) && ! in_array($operator, ['IN', 'NOT IN', 'BETWEEN', 'NOT BETWEEN'], true)) {
            $operator = in_array($operator, ['!=', '<>'], true) ? 'NOT IN' : 'IN';
        }

        return [
            'column' => $column,
            'operator' => $operator,
            'value' => $value,
        ];
    }

    /**
     * Compile normalized structure into SQL.
     *
     * Recursively builds the SQL string and collects prepare values.
     *
     * @param array{relation: string, clauses: list<mixed>} $normalized Normalized conditions tree.
     *
     * @return array{string, list<mixed>} [SQL Clause, Values Array].
     */
    private function compileConditions(array $normalized): array
    {
        if ($normalized['clauses'] === []) {
            return ['1=1', []];
        }

        $sqlParts = [];
        $values = [];

        foreach ($normalized['clauses'] as $clause) {
            if (! is_array($clause)) {
                continue;
            }

            // Case 1: Nested Group
            if (isset($clause['relation'], $clause['clauses'])) {
                /** @var array{relation: string, clauses: list<mixed>} $clause */
                [$nestedSql, $nestedValues] = $this->compileConditions($clause);

                if ($nestedSql !== '1=1') {
                    $sqlParts[] = "({$nestedSql})";
                    $values = array_merge($values, $nestedValues);
                }

                continue;
            }

            // Case 2: Single Condition
            /** @var array{column: string, operator: string, value: mixed} $clause */
            [$conditionSql, $conditionValues] = $this->compileSingleCondition($clause);

            if ($conditionSql === '') {
                continue;
            }

            $sqlParts[] = $conditionSql;
            $values = array_merge($values, $conditionValues);
        }

        if ($sqlParts === []) {
            return ['1=1', []];
        }

        $glue = " {$normalized['relation']} ";

        return [implode($glue, $sqlParts), $values];
    }

    /**
     * Compile a single valid condition.
     *
     * Dispatches to specific methods based on operator or value type.
     *
     * @param array{column: string, operator: string, value: mixed} $condition Validated condition.
     *
     * @return array{string, list<mixed>}
     */
    private function compileSingleCondition(array $condition): array
    {
        $column = $condition['column'];
        $operator = $condition['operator'];
        $value = $condition['value'];

        if ($value === null) {
            return $this->compileNullCondition($column, $operator);
        }

        if ($operator === 'BETWEEN' || $operator === 'NOT BETWEEN') {
            return $this->compileBetweenCondition($column, $operator, $value);
        }

        if ($operator === 'IN' || $operator === 'NOT IN') {
            return $this->compileInCondition($column, $operator, $value);
        }

        // Standard comparison (=, !=, >, <, LIKE)
        $placeholder = $this->getPlaceholder($value);

        return ["`{$column}` {$operator} {$placeholder}", [$value]];
    }

    /**
     * Compile IS NULL / IS NOT NULL clauses.
     *
     * Handles logic for null equality and inequality.
     *
     * @param string $column   The column name.
     * @param string $operator The operator used (e.g., '=', '!=').
     *
     * @return array{string, list<mixed>}
     */
    private function compileNullCondition(string $column, string $operator): array
    {
        if (in_array($operator, ['=', 'IN'], true)) {
            return ["`{$column}` IS NULL", []];
        }

        if (in_array($operator, ['!=', '<>', 'NOT IN'], true)) {
            return ["`{$column}` IS NOT NULL", []];
        }

        // "id > NULL" is logically always false (or null) in SQL.
        // We return 1=0 to safely ensure the query returns no results for this condition.
        return ['1=0', []];
    }

    /**
     * Compile BETWEEN range clauses.
     *
     * Ensures strict 2-value array and dynamic placeholders for start/end.
     *
     * @param string $column   The column name.
     * @param string $operator 'BETWEEN' or 'NOT BETWEEN'.
     * @param mixed  $value    Array containing exactly two values.
     *
     * @return array{string, list<mixed>}
     *
     * @throws InvalidArgumentException If value count is not 2.
     */
    private function compileBetweenCondition(string $column, string $operator, mixed $value): array
    {
        if (! is_array($value) || count($value) !== 2) {
            throw new InvalidArgumentException('BETWEEN requires exactly 2 values.');
        }

        $vals = array_values($value);
        $p1 = $this->getPlaceholder($vals[0]);
        $p2 = $this->getPlaceholder($vals[1]);

        return ["`{$column}` {$operator} {$p1} AND {$p2}", $vals];
    }

    /**
     * Compile IN / NOT IN list clauses.
     *
     * Handles empty lists logic (IN [] is impossible/false, NOT IN [] is true).
     *
     * @param string $column   The column name.
     * @param string $operator 'IN' or 'NOT IN'.
     * @param mixed  $value    Array of values.
     *
     * @return array{string, list<mixed>}
     */
    private function compileInCondition(string $column, string $operator, mixed $value): array
    {
        if (! is_array($value)) {
            $value = [$value];
        }

        if ($value === []) {
            // IN [] -> Always False (1=0)
            // NOT IN [] -> Always True (1=1)
            return $operator === 'IN' ? ['1=0', []] : ['1=1', []];
        }

        $placeholders = [];

        foreach ($value as $v) {
            $placeholders[] = $this->getPlaceholder($v);
        }

        $placeholderStr = implode(', ', $placeholders);

        return ["`{$column}` {$operator} ({$placeholderStr})", array_values($value)];
    }
}
