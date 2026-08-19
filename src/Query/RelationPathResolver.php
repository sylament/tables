<?php

declare(strict_types=1);

namespace Sylament\Tables\Query;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use LogicException;

final class RelationPathResolver
{
    /**
     * Null when the path names nothing to cross: it carries no dot, its head is
     * an alias the builder already declares — so the caller's own resolution
     * wins — the query declares no root entity, or the mapping resolves none of
     * it.
     *
     * @throws LogicException when the path ends in an association rather than a field
     */
    public static function analyseQueryPath(QueryBuilder $query, string $path): ?ResolvedRelationPath
    {
        $rootClass = self::queryRootClass($query, $path);

        if ($rootClass === null) {
            return null;
        }

        return self::analyse($query->getEntityManager(), $rootClass, $path);
    }

    /**
     * {@see analyseQueryPath()} for a caller that reads the hops and never the
     * field at the end: a path ending in an association answers null here
     * instead of throwing, because such a caller never consumes the segment
     * that would have been wrong.
     */
    public static function tryAnalyseQueryPath(QueryBuilder $query, string $path): ?ResolvedRelationPath
    {
        $rootClass = self::queryRootClass($query, $path);

        if ($rootClass === null) {
            return null;
        }

        return self::walk($query->getEntityManager(), $rootClass, $path)[0];
    }

    /**
     * Null when a step's remainder names no field and its head names no
     * association, so the mapping does not resolve the path at all.
     *
     * @param class-string $rootClass
     *
     * @throws LogicException when the path ends in an association rather than a field
     */
    public static function analyse(EntityManagerInterface $entityManager, string $rootClass, string $path): ?ResolvedRelationPath
    {
        [$resolved, $endsInAssociation] = self::walk($entityManager, $rootClass, $path);

        if ($endsInAssociation) {
            throw new LogicException(sprintf('Cannot resolve [%s]: the path ends in an association rather than a field — name a field on it, e.g. [%s.<field>].', $path, $path));
        }

        return $resolved;
    }

    /**
     * The entity a query path resolves against, or null where the path names
     * nothing for the mapping to cross.
     *
     * @return class-string|null
     */
    private static function queryRootClass(QueryBuilder $query, string $path): ?string
    {
        if (!str_contains($path, '.')) {
            return null;
        }

        if (in_array(explode('.', $path, 2)[0], $query->getAllAliases(), true)) {
            return null;
        }

        return $query->getRootEntities()[0] ?? null;
    }

    /**
     * The path split against the mapping, paired with whether it ran out of
     * segments standing on an association — the one unresolved ending its two
     * callers answer differently. Null and false where a step names neither a
     * field nor an association, which neither caller can do anything with.
     *
     * @param class-string $rootClass
     *
     * @return array{?ResolvedRelationPath, bool}
     */
    private static function walk(EntityManagerInterface $entityManager, string $rootClass, string $path): array
    {
        $segments = explode('.', $path);
        $hops = [];
        $currentClass = $rootClass;

        while ($segments !== []) {
            $metadata = $entityManager->getClassMetadata($currentClass);
            $remainder = implode('.', $segments);

            if ($metadata->hasField($remainder)) {
                return [new ResolvedRelationPath($hops, $remainder), false];
            }

            $head = $segments[0];

            if (!$metadata->hasAssociation($head)) {
                return [null, false];
            }

            $currentClass = $metadata->getAssociationTargetClass($head);
            $hops[] = new Hop($head, $currentClass, $metadata->isCollectionValuedAssociation($head));
            array_shift($segments);
        }

        return [null, true];
    }
}
