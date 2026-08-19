<?php

declare(strict_types=1);

namespace Sylament\Tables\Columns\Concerns;

use Closure;
use Doctrine\ORM\Query\Expr\Orx;
use Doctrine\ORM\Query\Expr\Select;
use Doctrine\ORM\QueryBuilder;
use Stringable;
use Sylament\Tables\Query\AggregateDeclaration;
use Sylament\Tables\Query\AggregateQuery;
use Sylament\Tables\Query\ColumnPathResolver;
use Sylament\Tables\Query\RelationJoiner;
use Sylament\Tables\Query\RelationPathResolver;
use Sylament\Tables\Query\ResolvedRelationPath;
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
     * A no-op unless the name resolves to a chain of to-one hops. Only the hops
     * are read, never the field at the end, so a name that sorting or searching
     * would refuse still renders — joined where its hops resolve, lazy where
     * they do not.
     */
    public function applyEagerLoading(QueryBuilder $query): QueryBuilder
    {
        $analysed = RelationPathResolver::tryAnalyseQueryPath($query, $this->getName());

        if (!$analysed instanceof ResolvedRelationPath || !$analysed->isRelationPath() || $analysed->hasToManyHop()) {
            return $query;
        }

        $parentAlias = $query->getRootAliases()[0] ?? null;

        if ($parentAlias === null) {
            return $query;
        }

        foreach ($analysed->hops as $hop) {
            $parentAlias = RelationJoiner::ensureJoined($query, $parentAlias, [$hop]);

            if (!in_array($parentAlias, self::selectedAliases($query), true)) {
                $query->addSelect($parentAlias);
            }
        }

        return $query;
    }

    /**
     * Every expression the builder selects, so a hop already fetched — by the
     * caller's own query or by a column that crosses the same relation — is not
     * selected twice.
     *
     * @return list<string>
     */
    private static function selectedAliases(QueryBuilder $query): array
    {
        $part = $query->getDQLPart('select');
        $selected = [];

        foreach (is_array($part) ? $part : [] as $select) {
            if (!$select instanceof Select) {
                continue;
            }

            foreach ($select->getParts() as $expression) {
                $selected[] = (string) $expression;
            }
        }

        return $selected;
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
