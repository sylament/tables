<?php

declare(strict_types=1);

namespace Sylament\Tables\Contracts;

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
}
