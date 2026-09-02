<?php

declare(strict_types=1);

namespace Sylament\Tables\Tests\Fixtures\Concerns;

use Doctrine\ORM\QueryBuilder;
use LogicException;
use Sylament\Schemas\Schema;
use Sylament\Support\Pagination\Paginator;
use Sylament\Tables\Contracts\InteractsWithTable;
use Sylament\Tables\Filters\Indicator;
use Sylament\Tables\Table;

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

    public function getTablePaginator(): ?Paginator
    {
        return null;
    }

    public function getTableRecordsPerPage(): int|string|null
    {
        return null;
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

    public function getAllSelectableTableRecordsCount(): ?int
    {
        return null;
    }

    public function deselectAllTableRecords(): void
    {
        //
    }
}
