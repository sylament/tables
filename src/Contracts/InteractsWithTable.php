<?php

declare(strict_types=1);

namespace Sylament\Tables\Contracts;

use Sylament\Support\Pagination\Paginator;
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
}
