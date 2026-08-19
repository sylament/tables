<?php

declare(strict_types=1);

namespace Sylament\Tables\Query;

use Doctrine\ORM\QueryBuilder;
use LogicException;

final class ColumnPathResolver
{
    public static function resolve(QueryBuilder $query, string $path): string
    {
        $rootAlias = $query->getRootAliases()[0]
            ?? throw new LogicException('The table query has no root alias to resolve column paths against.');

        if (!str_contains($path, '.')) {
            return "{$rootAlias}.{$path}";
        }

        $alias = explode('.', $path, 2)[0];

        if (in_array($alias, $query->getAllAliases(), true)) {
            return $path;
        }

        throw new LogicException(sprintf('Cannot resolve [%s] on the table query: [%s] is not an alias on the builder, a mapped field path, or an association path on [%s]. Join and alias the relation in query(), or take over with sortable(query:)/searchable(query:); dotted paths into JSON columns are not supported in the query lane.', $path, $alias, $query->getRootEntities()[0] ?? '(unknown)'));
    }

    /**
     * The orderable expression for a path: the caller's own resolution when the
     * mapping crosses nothing, otherwise the field read off the alias every
     * to-one hop is left-joined onto.
     *
     * @throws LogicException when a hop is to-many, which has no single row to order by
     */
    public static function resolveOrderable(QueryBuilder $query, string $path): string
    {
        $analysed = RelationPathResolver::analyseQueryPath($query, $path);

        if (!$analysed instanceof ResolvedRelationPath) {
            return self::resolve($query, $path);
        }

        $rootAlias = $query->getRootAliases()[0]
            ?? throw new LogicException('The table query has no root alias to resolve column paths against.');

        if (!$analysed->isRelationPath()) {
            return "{$rootAlias}.{$analysed->fieldPath}";
        }

        if ($analysed->hasToManyHop()) {
            throw new LogicException(sprintf('Cannot sort by [%s]: sorting through a relation supports to-one associations only. Take over with sortable(query:), or declare an aggregate column.', $path));
        }

        return RelationJoiner::ensureJoined($query, $rootAlias, $analysed->hops) . ".{$analysed->fieldPath}";
    }
}
