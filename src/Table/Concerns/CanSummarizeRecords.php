<?php

declare(strict_types=1);

namespace Sylament\Tables\Table\Concerns;

use Closure;
use Doctrine\ORM\QueryBuilder;
use Sylament\Tables\Columns\Column;

trait CanSummarizeRecords
{
    protected bool|Closure $hasPageSummary = true;

    protected bool|Closure $hasAllTableSummary = true;

    public function summaries(bool|Closure $pageCondition = true, bool|Closure $allTableCondition = true): static
    {
        $this->hasPageSummary = $pageCondition;
        $this->hasAllTableSummary = $allTableCondition;

        return $this;
    }

    public function hasPageSummary(): bool
    {
        return (bool) $this->evaluate($this->hasPageSummary);
    }

    public function hasAllTableSummary(): bool
    {
        return (bool) $this->evaluate($this->hasAllTableSummary);
    }

    /**
     * Whether anything on this table summarizes the query at all — read over every
     * declared column rather than the visible ones, as upstream: a column the user
     * toggled off still keeps the band rendered, and its cell is simply not in it.
     */
    public function hasSummary(QueryBuilder|Closure|null $query): bool
    {
        return array_any($this->getColumns(), static fn (Column $column): bool => $column->hasSummary($query));
    }
}
