<?php

declare(strict_types=1);

namespace Sylament\Tables\Query;

use Doctrine\ORM\Mapping\MappingException;
use Doctrine\ORM\QueryBuilder;
use LogicException;

final class RelationConstraint
{
    /**
     * The `EXISTS (…)` DQL a search term becomes when its path crosses a
     * relation: a subselect re-rooted on the same entity, joined along the path
     * and correlated back to the outer row by identifier, so a to-many hop
     * filters roots instead of multiplying them. The LIKE parameter is
     * registered on `$query` — the subselect shares the outer builder's
     * parameter space — and the returned string is for the caller to add to its
     * own predicate.
     *
     * @throws LogicException when the root entity has a composite identifier, which the correlation cannot express
     */
    public static function existsLike(QueryBuilder $query, ResolvedRelationPath $path, string $search, ?bool $forceCaseInsensitive): string
    {
        $rootAlias = $query->getRootAliases()[0]
            ?? throw new LogicException('The table query has no root alias to resolve column paths against.');
        $rootClass = $query->getRootEntities()[0]
            ?? throw new LogicException('The table query has no root entity to search relations from.');

        $metadata = $query->getEntityManager()->getClassMetadata($rootClass);

        try {
            $identifier = $metadata->getSingleIdentifierFieldName();
        } catch (MappingException) {
            throw new LogicException(sprintf('Cannot search across [%s]: the root entity [%s] has a composite identifier, which relation search does not support.', $path->fieldPath, $rootClass));
        }

        $subAlias = 'sy_x' . count($query->getParameters());
        $subselect = "SELECT {$subAlias}.{$identifier} FROM {$rootClass} {$subAlias}";
        $currentAlias = $subAlias;

        foreach ($path->hops as $index => $hop) {
            $joinAlias = "{$subAlias}_{$index}";
            $subselect .= " JOIN {$currentAlias}.{$hop->property} {$joinAlias}";
            $currentAlias = $joinAlias;
        }

        $like = SearchConstraint::like($query, "{$currentAlias}.{$path->fieldPath}", $search, $forceCaseInsensitive);

        return "EXISTS ({$subselect} WHERE {$subAlias}.{$identifier} = {$rootAlias}.{$identifier} AND {$like})";
    }
}
