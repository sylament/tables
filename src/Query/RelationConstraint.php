<?php

declare(strict_types=1);

namespace Sylament\Tables\Query;

use Doctrine\ORM\QueryBuilder;
use LogicException;
use Sylament\Schemas\Identity\IdentifierConstraint;

final class RelationConstraint
{
    /**
     * The `EXISTS (…)` DQL a search term becomes when its path crosses a
     * relation: a subselect re-rooted on the same entity, joined along the path
     * and correlated back to the outer row by every identifier field, so a
     * to-many hop filters roots instead of multiplying them. The LIKE parameter
     * is registered on `$query` — the subselect shares the outer builder's
     * parameter space — and the returned string is for the caller to add to its
     * own predicate.
     *
     * @throws LogicException when the query names no root alias or root entity to search relations from
     */
    public static function existsLike(QueryBuilder $query, ResolvedRelationPath $path, string $search, ?bool $forceCaseInsensitive): string
    {
        $rootAlias = $query->getRootAliases()[0]
            ?? throw new LogicException('The table query has no root alias to resolve column paths against.');
        $rootClass = $query->getRootEntities()[0]
            ?? throw new LogicException('The table query has no root entity to search relations from.');

        $metadata = $query->getEntityManager()->getClassMetadata($rootClass);
        $identifier = $metadata->identifier[0];

        $subAlias = 'sy_x' . count($query->getParameters());
        $projection = $metadata->hasAssociation($identifier)
            ? "IDENTITY({$subAlias}.{$identifier})"
            : "{$subAlias}.{$identifier}";

        $subselect = "SELECT {$projection} FROM {$rootClass} {$subAlias}";
        $currentAlias = $subAlias;

        foreach ($path->hops as $index => $hop) {
            $joinAlias = "{$subAlias}_{$index}";
            $subselect .= " JOIN {$currentAlias}.{$hop->property} {$joinAlias}";
            $currentAlias = $joinAlias;
        }

        $correlation = IdentifierConstraint::correlate($query, $subAlias, $rootClass, $rootAlias);
        $like = SearchConstraint::like($query, "{$currentAlias}.{$path->fieldPath}", $search, $forceCaseInsensitive, array_last($path->hops)?->targetClass);

        return "EXISTS ({$subselect} WHERE {$correlation} AND {$like})";
    }
}
