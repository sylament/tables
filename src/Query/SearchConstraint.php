<?php

declare(strict_types=1);

namespace Sylament\Tables\Query;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\ORM\Query\Expr\Comparison;
use Doctrine\ORM\QueryBuilder;

final class SearchConstraint
{
    public static function like(QueryBuilder $query, string $path, string $search, ?bool $forceCaseInsensitive): Comparison
    {
        $caseInsensitive = $forceCaseInsensitive
            ?? $query->getEntityManager()->getConnection()->getDatabasePlatform() instanceof PostgreSQLPlatform;

        $parameter = 'sylament_search_' . count($query->getParameters());

        if ($caseInsensitive) {
            $query->setParameter($parameter, '%' . mb_strtolower($search) . '%');

            return $query->expr()->like("LOWER({$path})", ":{$parameter}");
        }

        $query->setParameter($parameter, '%' . $search . '%');

        return $query->expr()->like($path, ":{$parameter}");
    }
}
