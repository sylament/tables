<?php

declare(strict_types=1);

namespace Sylament\Tables\Table\Concerns;

use Closure;
use Sylament\Support\Pagination\Paginator;

trait HasRecords
{
    protected ?Closure $dataSource = null;

    /**
     * @var class-string|null
     */
    protected ?string $cachedRecordClass = null;

    protected bool $hasCachedRecordClass = false;

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
     * Whether the table is on the query lane — a records closure wins over a
     * declared query, so this asks only whether one is set.
     */
    public function hasQuery(): bool
    {
        return $this->dataSource === null;
    }

    /**
     * @return class-string|null
     */
    public function getRecordClass(): ?string
    {
        if ($this->hasCachedRecordClass) {
            return $this->cachedRecordClass;
        }

        $this->hasCachedRecordClass = true;

        return $this->cachedRecordClass = array_first($this->getBaseQuery()?->getRootEntities() ?? []);
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
