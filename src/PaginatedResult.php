<?php

declare(strict_types=1);

namespace WPTechnix\WPModels;

use JsonSerializable;

defined('ABSPATH') || exit;

/**
 * Paginated Result Container.
 *
 * Immutable value object containing paginated query results with metadata.
 * Designed for API responses and template rendering.
 *
 * @template TEntity
 */
final class PaginatedResult implements JsonSerializable
{
    /**
     * Constructor.
     *
     * @param array<int, TEntity> $items      Result items keyed by ID.
     * @param int                 $total      Total records matching query.
     * @param int                 $page       Current page (1-indexed).
     * @param int                 $perPage    Items per page.
     * @param int                 $totalPages Total number of pages.
     */
    public function __construct(
        public readonly array $items,
        public readonly int $total,
        public readonly int $page,
        public readonly int $perPage,
        public readonly int $totalPages,
    ) {
    }

    /**
     * Create an empty result set.
     *
     * @param int $page    Current page.
     * @param int $perPage Items per page.
     *
     * @return self<TEntity> Empty paginated result.
     */
    public static function empty(int $page = 1, int $perPage = 10): self
    {
        return new self([], 0, $page, $perPage, 0);
    }

    /**
     * Check if there are no results.
     *
     * @return bool True if items array is empty.
     */
    public function isEmpty(): bool
    {
        return $this->items === [];
    }

    /**
     * Check if there are results.
     *
     * @return bool True if items array is not empty.
     */
    public function isNotEmpty(): bool
    {
        return $this->items !== [];
    }

    /**
     * Get number of items in current page.
     *
     * @return int The count of items.
     */
    public function count(): int
    {
        return count($this->items);
    }

    /**
     * Check if there is a next page available.
     *
     * @return bool True if current page is less than total pages.
     */
    public function hasNextPage(): bool
    {
        return $this->page < $this->totalPages;
    }

    /**
     * Check if there is a previous page available.
     *
     * @return bool True if current page is greater than 1.
     */
    public function hasPreviousPage(): bool
    {
        return $this->page > 1;
    }

    /**
     * Get next page number.
     *
     * @return int|null Next page number or null if on last page.
     */
    public function getNextPage(): ?int
    {
        return $this->hasNextPage() ? $this->page + 1 : null;
    }

    /**
     * Get previous page number.
     *
     * @return int|null Previous page number or null if on first page.
     */
    public function getPreviousPage(): ?int
    {
        return $this->hasPreviousPage() ? $this->page - 1 : null;
    }

    /**
     * Check if on first page.
     *
     * @return bool True if current page is 1.
     */
    public function isFirstPage(): bool
    {
        return $this->page === 1;
    }

    /**
     * Check if on last page.
     *
     * @return bool True if current page equals or exceeds total pages.
     */
    public function isLastPage(): bool
    {
        return $this->page >= $this->totalPages;
    }

    /**
     * Get the starting item number for display (1-indexed).
     *
     * E.g., "Showing 11-20 of 50" -> returns 11.
     *
     * @return int The starting item index.
     */
    public function getFromNumber(): int
    {
        if ($this->total === 0) {
            return 0;
        }

        return (($this->page - 1) * $this->perPage) + 1;
    }

    /**
     * Get the ending item number for display (1-indexed).
     *
     * E.g., "Showing 11-20 of 50" -> returns 20.
     *
     * @return int The ending item index.
     */
    public function getToNumber(): int
    {
        if ($this->total === 0) {
            return 0;
        }

        return min($this->total, $this->page * $this->perPage);
    }

    /**
     * Get items as a simple list (without ID keys).
     *
     * @return list<TEntity> The list of items.
     */
    public function getItemsList(): array
    {
        return array_values($this->items);
    }

    /**
     * Get item IDs.
     *
     * @return list<int> The list of item IDs.
     */
    public function getIds(): array
    {
        return array_keys($this->items);
    }

    /**
     * Get first item in the result set.
     *
     * @return TEntity|null The first item or null if empty.
     */
    public function first(): mixed
    {
        if ($this->items === []) {
            return null;
        }

        return $this->items[array_key_first($this->items)];
    }

    /**
     * Get last item in the result set.
     *
     * @return TEntity|null The last item or null if empty.
     */
    public function last(): mixed
    {
        if ($this->items === []) {
            return null;
        }

        return $this->items[array_key_last($this->items)];
    }

    /**
     * Map items through a callback function.
     *
     * @param callable(TEntity, int): TResult $callback The callback function.
     *
     * @return list<TResult> The mapped results.
     *
     * @template TResult
     */
    public function map(callable $callback): array
    {
        $results = [];

        foreach ($this->items as $id => $item) {
            $results[] = $callback($item, $id);
        }

        return $results;
    }

    /**
     * Get pagination metadata.
     *
     * @return array{total: int, page: int, per_page: int, total_pages: int, from: int, to: int} Metadata array.
     */
    public function getMeta(): array
    {
        return [
            'total' => $this->total,
            'page' => $this->page,
            'per_page' => $this->perPage,
            'total_pages' => $this->totalPages,
            'from' => $this->getFromNumber(),
            'to' => $this->getToNumber(),
        ];
    }

    /**
     * Convert to array for JSON serialization.
     *
     * @return array{
     *     items: array<int, TEntity>,
     *     meta: array{total: int,
     *       page: int,
     *       per_page: int,
     *       total_pages: int,
     *       from: int, to: int
     *    }
     * } Serialization array.
     */
    public function toArray(): array
    {
        return [
            'items' => $this->items,
            'meta' => $this->getMeta(),
        ];
    }

    /**
     * JSON serialization implementation.
     *
     * @return array{
     *     items: array<int, TEntity>,
     *     meta: array{
     *        total: int,
     *        page: int,
     *        per_page: int,
     *        total_pages: int,
     *        from: int,
     *        to: int
     *    }
     * } Data to serialize.
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * Generate page numbers for pagination UI.
     *
     * Returns an array of page numbers with null representing ellipsis.
     * E.g., [1, 2, 3, null, 10] for page 2 of 10.
     *
     * @param int $surroundingPages Number of pages to show around current page.
     *
     * @return list<int|null> List of page numbers and nulls.
     */
    public function getPageNumbers(int $surroundingPages = 2): array
    {
        if ($this->totalPages <= 1) {
            return $this->totalPages === 1 ? [1] : [];
        }

        $pages = [];
        $lastAdded = 0;

        for ($i = 1; $i <= $this->totalPages; $i++) {
            // Always show first page.
            $showFirst = $i === 1;

            // Always show last page.
            $showLast = $i === $this->totalPages;

            // Show pages around current.
            $showSurrounding = abs($i - $this->page) <= $surroundingPages;

            if (!$showFirst && !$showLast && !$showSurrounding) {
                continue;
            }

            // Add ellipsis if there's a gap.
            if ($lastAdded > 0 && $i - $lastAdded > 1) {
                $pages[] = null;
            }

            $pages[] = $i;
            $lastAdded = $i;
        }

        return $pages;
    }
}
