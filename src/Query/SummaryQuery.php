<?php

declare(strict_types=1);

namespace Sylament\Tables\Query;

use Doctrine\ORM\AbstractQuery;
use Doctrine\ORM\QueryBuilder;
use LogicException;

final class SummaryQuery
{
    /**
     * One scalar read of the aggregates named, over a clone of the table query
     * with its select replaced: the joins stay so an aggregate can name a
     * joined alias, while the fetch-join selects go with the old list.
     *
     * The order is reset and the paging cleared because an aggregate tolerates
     * neither — a leftover `ORDER BY` is a PostgreSQL error, and a `LIMIT`
     * bounds the aggregate's own result row rather than the aggregated set, so
     * a page summary written with one silently reads the whole table.
     *
     * An ungrouped aggregate returns exactly one row over any set, the empty
     * one included, so the read is a single result rather than a guarded list.
     *
     * @param list<string> $selects each `FN(path) AS alias`
     *
     * @return array<string, mixed> alias ⇒ raw driver value
     *
     * @throws LogicException when a to-many join on the query would inflate every aggregate
     */
    public static function readRow(QueryBuilder $query, array $selects): array
    {
        $aggregate = self::aggregable($query);

        /** @var array<string, mixed> $row */
        $row = $aggregate->select(...$selects)->getQuery()->getSingleResult(AbstractQuery::HYDRATE_ARRAY);

        return $row;
    }

    /**
     * The distinct values of one expression over the same clone, flattened —
     * raw driver values, which is what the list summary renders.
     *
     * @return list<mixed>
     *
     * @throws LogicException when a to-many join on the query would inflate every aggregate
     */
    public static function readDistinct(QueryBuilder $query, string $expression): array
    {
        /** @var list<mixed> $values */
        $values = self::aggregable($query)
            ->select("DISTINCT {$expression}")
            ->getQuery()
            ->getSingleColumnResult();

        return $values;
    }

    /**
     * The DQL expression a column's name aggregates over: a field off the root
     * alias, a to-one relation path off the alias its hops are joined under —
     * reusing a join the query already carries — or an embeddable field, which
     * is dotted without crossing anything.
     *
     * A to-many path is refused rather than joined: the join would multiply the
     * rows every other aggregate on the same statement reads.
     *
     * @throws LogicException when the path crosses a to-many association
     */
    public static function qualifyAttribute(QueryBuilder $query, string $name): string
    {
        $rootAlias = $query->getRootAliases()[0]
            ?? throw new LogicException(sprintf('Cannot summarize [%s]: the table query has no root alias to resolve it against.', $name));

        $analysed = RelationPathResolver::tryAnalyseQueryPath($query, $name);

        if (!$analysed instanceof ResolvedRelationPath || !$analysed->isRelationPath()) {
            return "{$rootAlias}.{$name}";
        }

        if ($analysed->hasToManyHop()) {
            throw new LogicException(sprintf('Cannot summarize [%s]: summarizing through a relation supports to-one associations only. Declare an aggregate column, or take over with Summarizer::using().', $name));
        }

        return RelationJoiner::ensureJoined($query, $rootAlias, $analysed->hops) . ".{$analysed->fieldPath}";
    }

    /**
     * The clone every summary read runs on, refusing the one query shape no
     * aggregate can answer over.
     *
     * @throws LogicException when a to-many join would multiply the rows aggregated
     */
    private static function aggregable(QueryBuilder $query): QueryBuilder
    {
        if (JoinCardinality::joinsCollection($query)) {
            throw new LogicException('Cannot summarize this table: its query joins a to-many association, which multiplies rows and inflates every aggregate over them. Drop the join, or take the summary over with Summarizer::using().');
        }

        return (clone $query)
            ->resetDQLPart('orderBy')
            ->setFirstResult(0)
            ->setMaxResults(null);
    }
}
