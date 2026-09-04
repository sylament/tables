<?php

declare(strict_types=1);

namespace Sylament\Tables\Query;

use Doctrine\ORM\Query\Expr\Join;
use Doctrine\ORM\Query\Expr\Select;
use Doctrine\ORM\QueryBuilder;

final class RelationJoiner
{
    /**
     * Fetch-joins the relation a dotted path reads through, so every reader of
     * it is answered from the one query rather than by a lazy load per row. A
     * no-op unless the path resolves to a chain of to-one hops. Only the hops
     * are read, never the field at the end, so a path that sorting or searching
     * would refuse still joins — where its hops resolve.
     */
    public static function fetchJoinPath(QueryBuilder $query, string $path): QueryBuilder
    {
        $analysed = RelationPathResolver::tryAnalyseQueryPath($query, $path);

        if (!$analysed instanceof ResolvedRelationPath || !$analysed->isRelationPath() || $analysed->hasToManyHop()) {
            return $query;
        }

        $parentAlias = $query->getRootAliases()[0] ?? null;

        if ($parentAlias === null) {
            return $query;
        }

        foreach ($analysed->hops as $hop) {
            $parentAlias = self::ensureJoined($query, $parentAlias, [$hop]);

            if (!in_array($parentAlias, self::selectedAliases($query), true)) {
                $query->addSelect($parentAlias);
            }
        }

        return $query;
    }

    /**
     * Left-joins every hop the builder does not already carry and returns the
     * alias the last one lands on — the parent alias itself when there are no
     * hops. A join already declared for the same parent alias and property is
     * reused under the alias it was given, so repeated calls add no duplicates.
     *
     * @param list<Hop> $hops
     */
    public static function ensureJoined(QueryBuilder $query, string $parentAlias, array $hops): string
    {
        foreach ($hops as $hop) {
            $join = "{$parentAlias}.{$hop->property}";
            $existing = self::findJoinAlias($query, $join);

            if ($existing !== null) {
                $parentAlias = $existing;

                continue;
            }

            $alias = self::mintAlias($query, $parentAlias, $hop->property);
            $query->leftJoin($join, $alias);
            $parentAlias = $alias;
        }

        return $parentAlias;
    }

    private static function findJoinAlias(QueryBuilder $query, string $join): ?string
    {
        foreach (self::joins($query) as $existing) {
            $alias = $existing->getAlias();

            if ($alias !== null && $existing->getJoin() === $join) {
                return $alias;
            }
        }

        return null;
    }

    /**
     * Every join expression on the builder, flattened across its root aliases.
     *
     * @return list<Join>
     */
    private static function joins(QueryBuilder $query): array
    {
        $part = $query->getDQLPart('join');
        $joins = [];

        foreach (is_array($part) ? $part : [] as $perRootAlias) {
            foreach (is_array($perRootAlias) ? $perRootAlias : [] as $join) {
                if ($join instanceof Join) {
                    $joins[] = $join;
                }
            }
        }

        return $joins;
    }

    /**
     * Every expression the builder selects, so a hop already fetched — by the
     * caller's own query or by another reader crossing the same relation — is
     * not selected twice.
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

    private static function mintAlias(QueryBuilder $query, string $parentAlias, string $property): string
    {
        $base = str_starts_with($parentAlias, 'sy_') ? "{$parentAlias}_{$property}" : "sy_{$property}";
        $alias = $base;
        $suffix = 2;

        while (in_array($alias, $query->getAllAliases(), true)) {
            $alias = "{$base}_{$suffix}";
            $suffix++;
        }

        return $alias;
    }
}
