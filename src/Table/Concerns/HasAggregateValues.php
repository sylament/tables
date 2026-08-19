<?php

declare(strict_types=1);

namespace Sylament\Tables\Table\Concerns;

/**
 * The side store a batched aggregate read fills for the resolved page, and the
 * only place a cell of an aggregate column reads its value from — keeping the
 * page query free of the grouped selects the value would otherwise need.
 */
trait HasAggregateValues
{
    /**
     * @var array<string, array<string, mixed>> column name ⇒ record key ⇒ value
     */
    protected array $aggregateValues = [];

    /**
     * @param array<string, mixed> $valuesByRecordKey one entry per key of the
     *                                                page, defaults included
     */
    public function setAggregateValues(string $columnName, array $valuesByRecordKey): void
    {
        $this->aggregateValues[$columnName] = $valuesByRecordKey;
    }

    public function hasAggregateValuesFor(string $columnName): bool
    {
        return array_key_exists($columnName, $this->aggregateValues);
    }

    public function getAggregateValue(string $columnName, string $recordKey): mixed
    {
        return $this->aggregateValues[$columnName][$recordKey] ?? null;
    }
}
