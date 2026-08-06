<?php

declare(strict_types=1);

namespace Sylament\Tables\Table\Concerns;

use Closure;

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
     * @return array<array-key, object|array<string, mixed>> keyed by record key
     */
    public function getRecords(): array
    {
        return $this->getLiveComponent()->getTableRecords();
    }

    /**
     * @param object|array<string, mixed> $record
     */
    public function getRecordKey(object|array $record): string
    {
        return $this->getLiveComponent()->getTableRecordKey($record);
    }
}
