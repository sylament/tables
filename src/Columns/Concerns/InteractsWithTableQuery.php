<?php

declare(strict_types=1);

namespace Sylament\Tables\Columns\Concerns;

use Closure;
use Doctrine\ORM\Query\Expr\Orx;
use Doctrine\ORM\QueryBuilder;
use Stringable;
use Sylament\Tables\Query\AggregateDeclaration;
use Sylament\Tables\Query\AggregateQuery;
use Sylament\Tables\Query\ColumnPathResolver;
use Sylament\Tables\Query\RelationJoiner;
use Sylament\Tables\Query\SearchConstraint;

trait InteractsWithTableQuery
{
    /**
     * Ordered by how explicit the declaration is: a `sortable(query:)` closure
     * first, then the columns a `sortable([...])` list named, then the
     * aggregate the cell reads, then the column's own name as a field path. So
     * an aggregate column claims the sort only where the user named no column
     * to sort by — `$sortColumns` is null exactly then, a list of any length
     * being the user's own.
     */
    public function applySort(QueryBuilder $query, string $direction = 'asc'): QueryBuilder
    {
        if ($this->sortQuery instanceof Closure) {
            $this->evaluate($this->sortQuery, [
                'direction' => $direction,
                'query' => $query,
            ]);

            return $query;
        }

        $declaration = $this->sortColumns === null ? $this->getCellAggregateDeclaration() : null;

        if ($declaration instanceof AggregateDeclaration) {
            $recordClass = $this->getTable()->getRecordClass();

            if ($recordClass !== null) {
                $query->addOrderBy(AggregateQuery::addHiddenSortSelect($query, $recordClass, $this->bindAggregateScope($declaration)), $direction);

                return $query;
            }
        }

        foreach ($this->getSortColumns() as $sortColumn) {
            $query->addOrderBy(ColumnPathResolver::resolveOrderable($query, $sortColumn), $direction);
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
            $searchExpression->add(SearchConstraint::forColumn($query, $searchColumn, $search, $this->isSearchForcedCaseInsensitive()));
        }
    }

    /**
     * Fetch-joins the relation this column reads through, so every cell of the
     * page is answered from the page query rather than by a lazy load per row.
     * The mechanism is shared with a `date()`-free grouping's own eager load,
     * which crosses the same paths under a different reader.
     */
    public function applyEagerLoading(QueryBuilder $query): QueryBuilder
    {
        return RelationJoiner::fetchJoinPath($query, $this->getName());
    }

    /**
     * The same declaration with its scope closure bound to this column's
     * evaluator, so the aggregate lane hands the query builder to a closure
     * that reads its injections exactly as the cell-filling batch does.
     */
    private function bindAggregateScope(AggregateDeclaration $declaration): AggregateDeclaration
    {
        $scope = $declaration->scope;

        if (!$scope instanceof Closure) {
            return $declaration;
        }

        return new AggregateDeclaration(
            function: $declaration->function,
            relationshipPath: $declaration->relationshipPath,
            column: $declaration->column,
            scope: fn (QueryBuilder $query, string $alias): mixed => $this->evaluate($scope, ['alias' => $alias, 'query' => $query]),
            castToBool: $declaration->castToBool,
        );
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
