<?php

declare(strict_types=1);

namespace Sylament\Tables\Tests\Fixtures\Concerns;

use Doctrine\ORM\QueryBuilder;
use LogicException;
use Sylament\Schemas\Schema;
use Sylament\Support\Pagination\Paginator;
use Sylament\Tables\Contracts\InteractsWithTable;
use Sylament\Tables\Filters\Indicator;
use Sylament\Tables\Grouping\Group;
use Sylament\Tables\Table;
use Twig\Markup;

/**
 * Proves the state-reader guard: a host may satisfy the contract without
 * `HasTable`, in which case a filter reading state must fail loudly.
 */
final class BareTableHost implements InteractsWithTable
{
    public function getTable(): Table
    {
        return Table::make($this);
    }

    public function getTableHostId(): string
    {
        return 'bare';
    }

    /**
     * @return array<array-key, object|array<string, mixed>>
     */
    public function getTableRecords(): array
    {
        return [];
    }

    /**
     * @return object|array<string, mixed>|null
     */
    public function getTableRecord(?string $key): object|array|null
    {
        return null;
    }

    public function flushCachedTableRecords(): void
    {
        //
    }

    /**
     * @param object|array<string, mixed> $record
     */
    public function getTableRecordKey(object|array $record): string
    {
        throw new LogicException('The bare table host resolves no record keys.');
    }

    public function isTableReordering(): bool
    {
        return false;
    }

    public function isTableLoaded(): bool
    {
        return true;
    }

    public function getTableSortColumn(): ?string
    {
        return null;
    }

    public function getTableSortDirection(): ?string
    {
        return null;
    }

    public function getTableGrouping(): ?Group
    {
        return null;
    }

    public function getTableGroupingColumn(): ?string
    {
        return null;
    }

    public function getTableGroupingDirection(): ?string
    {
        return null;
    }

    /**
     * @return array<array-key, array{key: ?string, title: string|Markup|null, description: string|Markup|null, isFirstOfGroup: bool, isLastOfGroup: bool, indexInGroup: int}>
     */
    public function getTableRecordGroupBoundaries(): array
    {
        return [];
    }

    public function getTablePaginator(): ?Paginator
    {
        return null;
    }

    public function getTableRecordsPerPage(): int|string|null
    {
        return null;
    }

    public function getTablePage(): int
    {
        return 1;
    }

    /**
     * @return array{message: string, input: mixed}|null
     */
    public function getTableColumnStateError(string $column, string $recordKey): ?array
    {
        return null;
    }

    public function getAllTableSummaryQuery(): ?QueryBuilder
    {
        return null;
    }

    public function getPageTableSummaryQuery(): ?QueryBuilder
    {
        return null;
    }

    /**
     * @param object|array<string, mixed> $record
     */
    public function getGroupedTableSummaryQuery(object|array $record): ?QueryBuilder
    {
        return null;
    }

    /**
     * @return array<string, mixed>
     */
    public function getTableSummarySelectedState(?QueryBuilder $query): array
    {
        return [];
    }

    public function getTableSearchIndicator(): ?Indicator
    {
        return null;
    }

    public function getTableFiltersForm(): Schema
    {
        throw new LogicException('The bare table host builds no filters form.');
    }

    public function isTableRecordSelected(string $key): bool
    {
        return false;
    }

    public function hasSelectedTableRecords(): bool
    {
        return false;
    }

    /**
     * @return list<object|array<string, mixed>>|list<string>
     */
    public function getSelectedTableRecords(bool $shouldFetchSelectedRecords = true): array
    {
        return [];
    }

    public function getSelectedTableRecordsQuery(bool $shouldFetchSelectedRecords = true): QueryBuilder
    {
        throw new LogicException('The bare table host selects no records.');
    }

    /**
     * @return list<string>
     */
    public function getGroupedSelectableTableRecordKeys(?string $group): array
    {
        return [];
    }

    public function getAllSelectableTableRecordsCount(): ?int
    {
        return null;
    }

    public function deselectAllTableRecords(): void
    {
        //
    }

    public function isTableColumnToggledHidden(string $name): bool
    {
        return false;
    }

    public function getTableColumnManagerItems(): array
    {
        return [];
    }

    public function getTableColumnSearch(string $column): string
    {
        return '';
    }

    /**
     * @return array<string, Indicator>
     */
    public function getTableColumnSearchIndicators(): array
    {
        return [];
    }
}
