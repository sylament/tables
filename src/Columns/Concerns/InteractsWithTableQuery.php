<?php

declare(strict_types=1);

namespace Sylament\Tables\Columns\Concerns;

use Closure;
use Doctrine\ORM\Query\Expr\Orx;
use Doctrine\ORM\QueryBuilder;
use Stringable;
use Sylament\Tables\Query\ColumnPathResolver;
use Sylament\Tables\Query\SearchConstraint;

trait InteractsWithTableQuery
{
    public function applySort(QueryBuilder $query, string $direction = 'asc'): QueryBuilder
    {
        if ($this->sortQuery instanceof Closure) {
            $this->evaluate($this->sortQuery, [
                'direction' => $direction,
                'query' => $query,
            ]);

            return $query;
        }

        foreach ($this->getSortColumns() as $sortColumn) {
            $query->addOrderBy(ColumnPathResolver::resolve($query, $sortColumn), $direction);
        }

        return $query;
    }

    public function applySearchConstraint(QueryBuilder $query, Orx $searchExpression, string $search): void
    {
        if ($this->searchQuery instanceof Closure) {
            $constraint = $this->evaluateSearchQuery($query, $search);

            if ($constraint instanceof QueryBuilder) {
                return;
            }

            if (is_string($constraint) || $constraint instanceof Stringable) {
                $searchExpression->add($constraint);
            }

            return;
        }

        foreach ($this->getSearchColumns() as $searchColumn) {
            $searchExpression->add(SearchConstraint::like(
                $query,
                ColumnPathResolver::resolve($query, $searchColumn),
                $search,
                $this->isSearchForcedCaseInsensitive(),
            ));
        }
    }

    /**
     * Whatever the search closure returned — an expression to add to the search
     * group, or the builder it constrained itself, which joins nothing.
     */
    protected function evaluateSearchQuery(QueryBuilder $query, string $search): mixed
    {
        return $this->evaluate($this->searchQuery, [
            'query' => $query,
            'search' => $search,
            'searchQuery' => $search,
        ]);
    }
}
