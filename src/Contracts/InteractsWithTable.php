<?php

declare(strict_types=1);

namespace Sylament\Tables\Contracts;

use Doctrine\ORM\QueryBuilder;
use Sylament\Schemas\Schema;
use Sylament\Support\Pagination\Paginator;
use Sylament\Tables\Filters\Indicator;
use Sylament\Tables\Table;

interface InteractsWithTable
{
    public function getTable(): Table;

    /**
     * The morph-key prefix every row and cell of this host's table is stamped with.
     */
    public function getTableHostId(): string;

    /**
     * @return array<array-key, object|array<string, mixed>>|Paginator keyed by record key
     */
    public function getTableRecords(): array|Paginator;

    /**
     * @return object|array<string, mixed>|null
     */
    public function getTableRecord(?string $key): object|array|null;

    /**
     * Drop the memoised page of records so the next read re-queries — what a
     * write from a mounted action needs before the table re-renders.
     */
    public function flushCachedTableRecords(): void;

    /**
     * @param object|array<string, mixed> $record
     */
    public function getTableRecordKey(object|array $record): string;

    public function getTableSortColumn(): ?string;

    public function getTableSortDirection(): ?string;

    public function getTablePaginator(): ?Paginator;

    public function getTableRecordsPerPage(): int|string|null;

    /**
     * The global-search chip for the indicators row, or null when nothing is
     * searched for. Read by `Table::getSearchIndicator()`.
     */
    public function getTableSearchIndicator(): ?Indicator;

    /**
     * The one schema holding every visible filter's fields, one `Group` per
     * filter. Read by the filters panel; a host that is not a schema host
     * throws rather than answering.
     */
    public function getTableFiltersForm(): Schema;

    /**
     * Whether the record under `$key` is selected — tracking-aware, so it
     * answers the deselection bag while the selection is inverted. Read by the
     * partial to render each checkbox's `checked`.
     */
    public function isTableRecordSelected(string $key): bool;

    /**
     * Whether anything is selected at all. Read by the partial to stamp the
     * server-side `hidden` on the selection banner and the bulk-only triggers.
     */
    public function hasSelectedTableRecords(): bool;

    /**
     * The selected records, re-derived server-side from the client's keys:
     * hydrated records, or their keys alone under `fetchSelectedRecords(false)`.
     * Read by a bulk action through this contract.
     *
     * @return list<object|array<string, mixed>>|list<string>
     */
    public function getSelectedTableRecords(bool $shouldFetchSelectedRecords = true): array;

    /**
     * The table's query narrowed to the selection, for a bulk action that
     * writes without hydrating. Query lane only — a records() table throws.
     */
    public function getSelectedTableRecordsQuery(bool $shouldFetchSelectedRecords = true): QueryBuilder;

    /**
     * How many records the user could select in total, or null where the table
     * carries no total. Read by the partial as the banner's ceiling.
     */
    public function getAllSelectableTableRecordsCount(): ?int;

    /**
     * Clears the selection server-side. Called by the filter and search hooks,
     * and by a bulk action that deselects after completion.
     */
    public function deselectAllTableRecords(): void;
}
