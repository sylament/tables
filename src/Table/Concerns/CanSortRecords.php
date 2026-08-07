<?php

declare(strict_types=1);

namespace Sylament\Tables\Table\Concerns;

use Closure;
use Doctrine\ORM\QueryBuilder;
use Sylament\Tables\Columns\Column;

trait CanSortRecords
{
    protected string|Closure|null $defaultSort = null;

    protected string|Closure|null $defaultSortDirection = null;

    protected bool|Closure $hasDefaultKeySort = true;

    public function defaultSort(string|Closure|null $column, string|Closure|null $direction = 'asc'): static
    {
        $this->defaultSort = $column;
        $this->defaultSortDirection = $direction;

        return $this;
    }

    public function defaultKeySort(bool|Closure $condition = true): static
    {
        $this->hasDefaultKeySort = $condition;

        return $this;
    }

    public function getSortableVisibleColumn(string $name): ?Column
    {
        $column = $this->getColumn($name);

        if (!$column instanceof Column) {
            return null;
        }

        if (!$column->isSortable()) {
            return null;
        }

        return $column;
    }

    /**
     * A column name to order by, a builder the closure ordered and returned in
     * place of `$query`, or null when the table declares no default sort.
     */
    public function getDefaultSort(QueryBuilder $query, string $direction): string|QueryBuilder|null
    {
        return $this->evaluate($this->defaultSort, [
            'direction' => $direction,
            'query' => $query,
        ]);
    }

    public function getDefaultSortDirection(): ?string
    {
        $direction = $this->evaluate($this->defaultSortDirection);

        if ($direction === null) {
            return null;
        }

        return mb_strtolower($direction);
    }

    public function getSortColumn(): ?string
    {
        return $this->getLiveComponent()->getTableSortColumn();
    }

    public function getSortDirection(): ?string
    {
        return $this->getLiveComponent()->getTableSortDirection();
    }

    public function hasDefaultKeySort(): bool
    {
        return (bool) $this->evaluate($this->hasDefaultKeySort);
    }
}
