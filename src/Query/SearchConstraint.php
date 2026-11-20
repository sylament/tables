<?php

declare(strict_types=1);

namespace Sylament\Tables\Query;

use Doctrine\ORM\Query\Expr\Comparison;
use Doctrine\ORM\QueryBuilder;
use LogicException;
use Sylament\Schemas\Query\QueryAliases;

final class SearchConstraint
{
    /**
     * @param class-string|null $class the entity the path's alias stands for, when the builder does not declare that alias
     */
    public static function like(QueryBuilder $query, string $path, string $search, ?bool $forceCaseInsensitive, ?string $class = null): Comparison
    {
        return \Sylament\Schemas\Query\SearchConstraint::like($query, $path, $search, $forceCaseInsensitive, $class);
    }

    /**
     * The predicate a search column becomes, whichever tier declared it. A path
     * the mapping crosses associations for becomes the correlated `EXISTS (…)`
     * DQL string {@see RelationConstraint::existsLike()} builds, so a to-many
     * hop filters roots instead of multiplying them; everything else becomes a
     * `LIKE` comparison — read off the root alias when the mapping resolves the
     * path without crossing anything (an embeddable field), off the caller's own
     * resolution otherwise. Either arm is for the caller to add to its own
     * predicate. A dotted column whose head is an alias the query fetch-joins as a
     * collection is refused, since the caller's own resolution would filter each
     * row's collection rather than choose rows.
     *
     * @throws LogicException when the query names no root alias to read a mapped field off, or when the column's head is a fetch-joined collection alias
     */
    public static function forColumn(QueryBuilder $query, string $column, string $search, ?bool $forceCaseInsensitive): string|Comparison
    {
        $analysed = RelationPathResolver::analyseQueryPath($query, $column);

        if ($analysed instanceof ResolvedRelationPath && $analysed->isRelationPath()) {
            return RelationConstraint::existsLike($query, $analysed, $search, $forceCaseInsensitive);
        }

        if (!$analysed instanceof ResolvedRelationPath) {
            self::refuseFetchJoinedCollectionHead($query, $column);
        }

        $path = match (true) {
            !$analysed instanceof ResolvedRelationPath => ColumnPathResolver::resolve($query, $column),
            default => ($query->getRootAliases()[0] ?? throw new LogicException('The query has no root alias to resolve a searchable path against.')) . ".{$analysed->fieldPath}",
        };

        return self::like($query, $path, $search, $forceCaseInsensitive);
    }

    /**
     * @throws LogicException when the column's head is an alias the query fetch-joins as a collection, where a plain LIKE would filter each row's collection instead of choosing rows
     */
    private static function refuseFetchJoinedCollectionHead(QueryBuilder $query, string $column): void
    {
        if (!str_contains($column, '.')) {
            return;
        }

        $head = explode('.', $column, 2)[0];

        if (QueryAliases::isFetchJoinedCollection($query, $head)) {
            throw new LogicException(sprintf("The searchable path [%s] begins with [%s], an alias the query fetch-joins as a collection, so a search would narrow each row's [%s] rather than choose rows. Alias that join something the path does not begin with; a table column can also take its search over with searchable(query:).", $column, $head, $head));
        }
    }
}
