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

        throw new LogicException(sprintf('Cannot resolve [%s] on the table query: [%s] is not an alias on the builder. Join and alias the relation in query(), or take over with sortable(query:)/searchable(query:); automatic relationship traversal arrives with the Doctrine relation layer.', $path, $alias));
    }
}
