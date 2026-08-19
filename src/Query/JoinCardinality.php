<?php

declare(strict_types=1);

namespace Sylament\Tables\Query;

use Doctrine\ORM\Query\Expr\Join;
use Doctrine\ORM\QueryBuilder;

final class JoinCardinality
{
    /**
     * Whether any join on the builder can fetch a collection, which is what
     * Doctrine's `Tools\Pagination\Paginator` needs `$fetchJoinCollection` for:
     * a to-one join multiplies no rows, so paging it as rows is already correct.
     * True for a collection-valued association, and true for anything the
     * mapping cannot classify — an arbitrary-class join, a join off an alias
     * this builder never declared, a join on a property that is no association.
     */
    public static function joinsCollection(QueryBuilder $query): bool
    {
        $joinGroups = $query->getDQLPart('join');

        if (!is_array($joinGroups) || $joinGroups === []) {
            return false;
        }

        $classByAlias = [];

        foreach ($query->getRootAliases() as $i => $rootAlias) {
            $rootClass = $query->getRootEntities()[$i] ?? null;

            if ($rootClass !== null) {
                $classByAlias[$rootAlias] = $rootClass;
            }
        }

        $entityManager = $query->getEntityManager();

        foreach ($joinGroups as $joins) {
            foreach (is_array($joins) ? $joins : [] as $join) {
                if (!$join instanceof Join) {
                    continue;
                }

                $expression = $join->getJoin();

                if (!str_contains($expression, '.')) {
                    return true;
                }

                [$parentAlias, $property] = explode('.', $expression, 2);
                $parentClass = $classByAlias[$parentAlias] ?? null;

                if ($parentClass === null) {
                    return true;
                }

                $metadata = $entityManager->getClassMetadata($parentClass);

                if (!$metadata->hasAssociation($property)) {
                    return true;
                }

                if ($metadata->isCollectionValuedAssociation($property)) {
                    return true;
                }

                if ($join->getAlias() !== null) {
                    $classByAlias[$join->getAlias()] = $metadata->getAssociationTargetClass($property);
                }
            }
        }

        return false;
    }
}
