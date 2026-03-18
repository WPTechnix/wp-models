<?php

declare(strict_types=1);

namespace WPTechnix\WPModels\Tests\Unit;

use WPTechnix\WPModels\ClauseBuilder;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * @covers \WPTechnix\WPModels\ClauseBuilder
 * @testdox Database Conditions Compiler
 */
final class ClauseBuilderTest extends TestCase
{
    private ClauseBuilder $compiler;

    protected function setUp(): void
    {

        parent::setUp();

        $allowedColumns = [
            'id' => true,
            'name' => true,
            'status' => true,
            'price' => true,
            'is_active' => true,
            'created_at' => true,
        ];

        $this->compiler = new ClauseBuilder($allowedColumns, 'id');
    }

    // -------------------------------------------------------------------------
    // 1. Utilities & Helpers
    // -------------------------------------------------------------------------

    /**
     * @testdox correctly determines placeholders for: $type
     * @dataProvider placeholderProvider
     */
    public function testGetPlaceholder(string $type, mixed $value, string $expected): void
    {
        $this->assertSame($expected, $this->compiler->getPlaceholder($value));
    }

    public static function placeholderProvider(): array
    {
        return [
            ['integer', 123, '%d'],
            ['boolean true', true, '%d'],
            ['boolean false', false, '%d'],
            ['float', 12.50, '%f'],
            ['string', 'hello', '%s'],
            ['array', [1, 2], '%s'],
        ];
    }

    /** @testdox converts key-value attributes to condition arrays */
    public function testAttributesToConditions(): void
    {
        $attributes = ['name' => 'John', 'status' => 'active'];
        $expected = [
            ['column' => 'name', 'value' => 'John'],
            ['column' => 'status', 'value' => 'active'],
        ];

        $this->assertSame($expected, $this->compiler->attributesToConditions($attributes));
    }

    /** @testdox attributesToConditions handles empty input gracefully */
    public function testAttributesToConditionsEmpty(): void
    {
        $this->assertSame([], $this->compiler->attributesToConditions([]));
    }

    // -------------------------------------------------------------------------
    // 2. Order By Logic
    // -------------------------------------------------------------------------

    /** @testdox defaults to Primary Key DESC if order array is empty */
    public function testBuildOrderByDefaultsToPk(): void
    {
        $this->assertSame('`id` DESC', $this->compiler->buildOrderBy([]));
    }

    /** @testdox builds valid SQL for multiple order columns with case-insensitive direction */
    public function testBuildOrderByMultiple(): void
    {
        // Testing mixed case 'asc' vs 'DESC' to ensure normalization works
        $sql = $this->compiler->buildOrderBy([
            'status' => 'DESC',
            'created_at' => 'asc'
        ]);
        $this->assertSame('`status` DESC, `created_at` ASC', $sql);
    }

    /** @testdox throws exception when ordering by disallowed column */
    public function testBuildOrderByThrowsExceptionForInvalidColumn(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Column not available for sorting: hacking_attempt');
        $this->compiler->buildOrderBy(['hacking_attempt' => 'ASC']);
    }

    // -------------------------------------------------------------------------
    // 3. Where Clause: Standard Operators
    // -------------------------------------------------------------------------

    /** @testdox generates 1=1 for empty conditions */
    public function testBuildWhereEmpty(): void
    {
        [$sql, $values] = $this->compiler->buildWhere([]);
        $this->assertSame('1=1', $sql);
        $this->assertEmpty($values);
    }

    /**
     * @testdox compiles operator: $operator
     * @dataProvider operatorProvider
     */
    public function testBuildWhereOperators(string $operator, mixed $value, string $expectedSql, array $expectedValues): void
    {
        [$sql, $values] = $this->compiler->buildWhere([
            'column' => 'status',
            'operator' => $operator,
            'value' => $value
        ]);

        $this->assertSame($expectedSql, $sql);
        $this->assertSame($expectedValues, $values);
    }

    public static function operatorProvider(): array
    {
        return [
            'Implicit Equals' => [ '=', 'active', '`status` = %s', ['active']],
            'Explicit Not Equals' => ['!=', 'pending', '`status` != %s', ['pending']],
            'Less Than' => ['<', 5, '`status` < %d', [5]],
            'Greater Than' => ['>', 10.5, '`status` > %f', [10.5]],
            'Like Wildcard' => ['LIKE', '%active%', '`status` LIKE %s', ['%active%']],
            'Not Like' => ['NOT LIKE', 'deleted%', '`status` NOT LIKE %s', ['deleted%']],
        ];
    }

    // -------------------------------------------------------------------------
    // 4. Where Clause: Null Handling
    // -------------------------------------------------------------------------

    /** @testdox implicit NULL value creates IS NULL clause */
    public function testBuildWhereImplicitIsNull(): void
    {
        [$sql, $values] = $this->compiler->buildWhere(['column' => 'name', 'value' => null]);
        $this->assertSame('`name` IS NULL', $sql);
        $this->assertEmpty($values);
    }

    /** @testdox explicit != NULL creates IS NOT NULL clause */
    public function testBuildWhereExplicitIsNotNull(): void
    {
        [$sql, $values] = $this->compiler->buildWhere(['column' => 'name', 'operator' => '!=', 'value' => null]);
        $this->assertSame('`name` IS NOT NULL', $sql);
        $this->assertEmpty($values);
    }

    /** @testdox invalid operators with NULL (e.g., > NULL) return 1=0 safety clause */
    public function testBuildWhereNullWithInvalidOperator(): void
    {
        [$sql] = $this->compiler->buildWhere(['column' => 'name', 'operator' => '>', 'value' => null]);
        $this->assertSame('1=0', $sql);
    }

    // -------------------------------------------------------------------------
    // 5. Where Clause: Array & Magic Logic
    // -------------------------------------------------------------------------

    /** @testdox automatically converts array value to IN clause */
    public function testBuildWhereImplicitIn(): void
    {
        [$sql, $values] = $this->compiler->buildWhere(['column' => 'id', 'value' => [1, 2, 3]]);
        $this->assertSame('`id` IN (%d, %d, %d)', $sql);
        $this->assertSame([1, 2, 3], $values);
    }

    /** @testdox automatically converts array value with != operator to NOT IN clause */
    public function testBuildWhereImplicitNotIn(): void
    {
        [$sql, $values] = $this->compiler->buildWhere(['column' => 'id', 'operator' => '!=', 'value' => [1, 2]]);
        $this->assertSame('`id` NOT IN (%d, %d)', $sql);
    }

    /** @testdox Magic Fix: converts nonsensical operators (>, <) to IN when value is array */
    public function testBuildWhereForcesInForArraysWithInvalidOperators(): void
    {
        // SQL `id > (1, 2)` is invalid. Class should force this to `id IN (1, 2)`
        [$sql, $values] = $this->compiler->buildWhere([
            'column' => 'id',
            'operator' => '>',
            'value' => [1, 2]
        ]);

        $this->assertSame('`id` IN (%d, %d)', $sql);
        $this->assertSame([1, 2], $values);
    }

    /** @testdox Magic Fix: converts nonsensical operators (<>) to NOT IN when value is array */
    public function testBuildWhereForcesNotInForArraysWithNotEqualAliases(): void
    {
        [$sql, $values] = $this->compiler->buildWhere([
            'column' => 'id',
            'operator' => '<>',
            'value' => [1, 2]
        ]);

        $this->assertSame('`id` NOT IN (%d, %d)', $sql);
    }

    /** @testdox handles explicit IN operator with a scalar value (auto-wraps to array) */
    public function testBuildWhereExplicitInWithScalar(): void
    {
        $conditions = ['column' => 'id', 'operator' => 'IN', 'value' => 5];
        [$sql, $values] = $this->compiler->buildWhere($conditions);

        $this->assertSame('`id` IN (%d)', $sql);
        $this->assertSame([5], $values);
    }

    /** @testdox compiles BETWEEN clauses with dynamic placeholders */
    public function testBuildWhereBetween(): void
    {
        [$sql1, $val1] = $this->compiler->buildWhere(['column' => 'price', 'operator' => 'BETWEEN', 'value' => [10, 20]]);
        $this->assertSame('`price` BETWEEN %d AND %d', $sql1);
        $this->assertSame([10, 20], $val1);
    }

    /** @testdox throws exception if BETWEEN does not have exactly 2 values */
    public function testBuildWhereBetweenThrowsExceptionForInvalidCount(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('BETWEEN requires exactly 2 values.');
        $this->compiler->buildWhere(['column' => 'price', 'operator' => 'BETWEEN', 'value' => [10]]);
    }

    // -------------------------------------------------------------------------
    // 6. Grouping & Recursion
    // -------------------------------------------------------------------------

    /** @testdox compiles complex nested AND/OR groups recursively */
    public function testBuildWhereDeeplyNested(): void
    {
        $conditions = [
            'relation' => 'OR',
            ['column' => 'id', 'value' => 1],
            [
                'relation' => 'AND',
                ['column' => 'price', 'value' => 10],
                ['column' => 'status', 'value' => 'active']
            ]
        ];

        [$sql, $values] = $this->compiler->buildWhere($conditions);

        $expected = '`id` = %d OR (`price` = %d AND `status` = %s)';
        $this->assertSame($expected, $sql);
        $this->assertSame([1, 10, 'active'], $values);
    }

    /** @testdox defaults to implicit AND when list provided without relation key */
    public function testBuildWhereDefaultsToImplicitAnd(): void
    {
        $conditions = [
            ['column' => 'status', 'value' => 'active'],
            ['column' => 'price', 'operator' => '>', 'value' => 10],
        ];

        [$sql, $values] = $this->compiler->buildWhere($conditions);

        $this->assertSame('`status` = %s AND `price` > %d', $sql);
        $this->assertSame(['active', 10], $values);
    }

    /** @testdox allows duplicate logical conditions (does not de-duplicate) */
    public function testBuildWhereAllowsDuplicateConditions(): void
    {
        // SQL allows `a=1 OR a=1`. The compiler should not interfere.
        $conditions = [
            'relation' => 'OR',
            ['column' => 'status', 'value' => 'active'],
            ['column' => 'status', 'value' => 'active']
        ];

        [$sql, $values] = $this->compiler->buildWhere($conditions);
        $this->assertSame('`status` = %s OR `status` = %s', $sql);
        $this->assertSame(['active', 'active'], $values);
    }

    /** @testdox ignores groups that contain only a relation but no valid conditions */
    public function testBuildWhereIgnoresEmptyGroups(): void
    {
        $conditions = [
            ['column' => 'id', 'value' => 1],
            ['relation' => 'OR'] // Empty group
        ];

        [$sql, $values] = $this->compiler->buildWhere($conditions);
        $this->assertSame('`id` = %d', $sql);
        $this->assertSame([1], $values);
    }

    // -------------------------------------------------------------------------
    // 7. Edge Cases & Data Integrity
    // -------------------------------------------------------------------------

    /** @testdox preserves falsy values (0, "0", false) and does not treat them as null */
    public function testBuildWhereHandlesFalsyValues(): void
    {
        $conditions = [
            ['column' => 'id', 'value' => 0],
            ['column' => 'name', 'value' => '0'],
            ['column' => 'is_active', 'value' => false],
        ];

        [$sql, $values] = $this->compiler->buildWhere($conditions);
        $this->assertSame('`id` = %d AND `name` = %s AND `is_active` = %d', $sql);
        $this->assertSame([0, '0', false], $values);
    }

    /** @testdox handles mixed types within an IN clause array */
    public function testBuildWhereMixedTypesInInClause(): void
    {
        $conditions = ['column' => 'id', 'value' => [1, 'apple', 3.5]];
        [$sql, $values] = $this->compiler->buildWhere($conditions);

        $this->assertSame('`id` IN (%d, %s, %f)', $sql);
        $this->assertSame([1, 'apple', 3.5], $values);
    }

    /** @testdox normalizes sloppy input (trimming spaces, case insensitivity) */
    public function testBuildWhereNormalizesInput(): void
    {
        $conditions = [
            'relation' => ' or ',
            ['column' => 'price', 'operator' => ' >= ', 'value' => 10],
        ];

        [$sql] = $this->compiler->buildWhere($conditions);
        $this->assertSame('`price` >= %d', $sql);
    }

    // -------------------------------------------------------------------------
    // 8. Exception Handling
    // -------------------------------------------------------------------------

    /** @testdox throws exception for columns not in allow-list */
    public function testInvalidColumnThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Column not queryable: password');
        $this->compiler->buildWhere(['column' => 'password', 'value' => '123']);
    }

    /** @testdox throws exception for malformed condition structures */
    public function testMalformedStructureThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Condition must have a "column" key');
        $this->compiler->buildWhere([['value' => '123']]);
    }

    /** @testdox throws exception for invalid operators */
    public function testInvalidOperatorThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid operator: ===');
        $this->compiler->buildWhere(['column' => 'id', 'operator' => '===', 'value' => 1]);
    }

    /** @testdox throws exception for invalid relations */
    public function testInvalidRelationThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid relation: XOR');
        $this->compiler->buildWhere(['relation' => 'XOR', ['column' => 'id', 'value' => 1]]);
    }
}
