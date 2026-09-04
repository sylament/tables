<?php

declare(strict_types=1);

namespace Sylament\Tables\Table\Concerns;

use Closure;
use Doctrine\ORM\QueryBuilder;
use Sylament\Schemas\State\Relationship;
use Sylament\Tables\Columns\Column;

trait CanSortRecords
{
    protected string|Closure|null $defaultSort = null;

    protected string|Closure|null $defaultSortDirection = null;

    protected bool|Closure $hasDefaultKeySort = true;

    protected bool|Closure $persistsSortInSession = false;

    public function persistSortInSession(bool|Closure $condition = true): static
    {
        $this->persistsSortInSession = $condition;

        return $this;
    }

    public function persistsSortInSession(): bool
    {
        return (bool) $this->evaluate($this->persistsSortInSession);
    }

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

        if ($column->isHidden()) {
            return null;
        }

        if (!$column->isSortable()) {
            return null;
        }

        return $column;
    }

    /**
     * A column name to order by, a builder the closure ordered and returned in
     * place of `$query`, or null when neither the table nor — on the
     * relationship lane — its mapping declares a default sort.
     */
    public function getDefaultSort(QueryBuilder $query, string $direction): string|QueryBuilder|null
    {
        return $this->evaluate($this->defaultSort, [
            'direction' => $direction,
            'query' => $query,
        ]) ?? $this->getRelationshipDefaultSort($query);
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

    /**
     * The mapping's own order — `#[ORM\OrderBy]` on the association — appended
     * as the default when the table declares none, so the table lists the
     * collection in the order every other read of it uses; a clicked sort was
     * applied before this runs and wins. Only the lane actually in force has a
     * mapping to read, so a table whose `query()` won over its relationship is
     * left alone — its root is the query's, which the mapping's fields do not
     * name, and the closure that would resolve the owner stays unevaluated.
     */
    private function getRelationshipDefaultSort(QueryBuilder $query): ?QueryBuilder
    {
        if (!$this->isRelationshipBaseQuery()) {
            return null;
        }

        $relationship = $this->getRelationship();
        $rootAlias = $query->getRootAliases()[0] ?? null;

        if (!$relationship instanceof Relationship || $rootAlias === null) {
            return null;
        }

        $orderBy = $this->getAssociationReader()->describeRelationship($relationship)->orderBy;

        if ($orderBy === []) {
            return null;
        }

        foreach ($orderBy as $field => $direction) {
            $query->addOrderBy(sprintf('%s.%s', $rootAlias, $field), mb_strtolower($direction) === 'desc' ? 'desc' : 'asc');
        }

        return $query;
    }
}
