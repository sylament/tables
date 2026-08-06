<?php

declare(strict_types=1);

namespace Sylament\Tables\Table\Concerns;

use Closure;
use Sylament\Support\Pagination\Paginator;

trait HasRecords
{
    protected ?Closure $dataSource = null;

    public function records(?Closure $dataSource): static
    {
        $this->dataSource = $dataSource;

        return $this;
    }

    public function getDataSource(): ?Closure
    {
        return $this->dataSource;
    }

    /**
     * The current page's records on a paginated table — the counts around them
     * are `getPaginator()`.
     *
     * @return array<array-key, object|array<string, mixed>> keyed by record key
     */
    public function getRecords(): array
    {
        $records = $this->getLiveComponent()->getTableRecords();

        return $records instanceof Paginator ? $records->getRecords() : $records;
    }

    public function getPaginator(): ?Paginator
    {
        return $this->getLiveComponent()->getTablePaginator();
    }

    /**
     * @param object|array<string, mixed> $record
     */
    public function getRecordKey(object|array $record): string
    {
        return $this->getLiveComponent()->getTableRecordKey($record);
    }
}
