<?php

declare(strict_types=1);

namespace Sylament\Tables\Query;

use Doctrine\ORM\Query;
use Doctrine\ORM\Query\Expr\OrderBy;
use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\Tools\Pagination\LimitSubqueryOutputWalker;
use LogicException;
use Sylament\Schemas\Identity\IdentifierConstraint;
use Sylament\Schemas\Query\SortDirections;

final class FetchJoinPageQuery
{
    private const string KEY_ALIAS = 'sy_page_key';

    private const string ORDER_ALIAS = 'sy_order_';

    private const string PARAMETER_BASE = 'sylament_page_record_keys';

    /**
     * One window of records off a query that joins a collection and roots on a
     * class Doctrine's own pagination cannot key — two or more identifier
     * fields, or one that is an association.
     *
     * Two statements: the page's identifiers through Doctrine's own
     * `LimitSubqueryOutputWalker`, then the records off the same builder,
     * unwindowed and narrowed to those keys, so every collection hydrates
     * whole and the order is the page query's own.
     *
     * Both steps run on clones: the builder handed in is never windowed,
     * narrowed or reordered.
     *
     * @param class-string $recordClass
     *
     * @return list<object|array<string, mixed>>
     *
     * @throws LogicException when the query names no root alias, or the limit
     *                        walker answers a row of another width than the
     *                        identifier
     */
    public static function read(QueryBuilder $query, string $recordClass, int $firstResult, int $maxResults): array
    {
        $rootAlias = $query->getRootAliases()[0]
            ?? throw new LogicException(sprintf('Cannot page [%s] through its identifiers: the table query has no root alias to read them off.', $recordClass));

        $keys = self::readKeys($query, $rootAlias, $recordClass, $firstResult, $maxResults);

        if ($keys === []) {
            return [];
        }

        $recordQuery = clone $query;

        $recordQuery
            ->setFirstResult(0)
            ->setMaxResults(null)
            ->andWhere(IdentifierConstraint::in($recordQuery, $rootAlias, $recordClass, $keys, self::PARAMETER_BASE));

        /** @var array<array-key, object|array<string, mixed>> $records */
        $records = $recordQuery->getQuery()->getResult();

        return array_values($records);
    }

    /**
     * The page's record keys, in the window's own order.
     *
     * The walker names its columns itself — an identifier association under the
     * bare property name carrying the raw driver value, a scalar field under
     * `<alias>_<field>` and already converted through its type — so the row is
     * remapped positionally onto the aliases this class asked `select()` for and
     * minted through `keyOfHydrated()`, which converts the association halves
     * alone.
     *
     * @param class-string $recordClass
     *
     * @return list<string>
     *
     * @throws LogicException when a row is not the identifier the walker selected
     */
    private static function readKeys(QueryBuilder $query, string $rootAlias, string $recordClass, int $firstResult, int $maxResults): array
    {
        $selection = IdentifierConstraint::select($query, $rootAlias, $recordClass, self::KEY_ALIAS);
        $width = count($selection->expressions);

        $idQuery = clone $query;

        self::orderAssociationsByHiddenIdentity($idQuery, $rootAlias, $recordClass);

        $rows = $idQuery->getQuery()
            ->setHint(Query::HINT_CUSTOM_OUTPUT_WALKER, LimitSubqueryOutputWalker::class)
            ->setFirstResult($firstResult)
            ->setMaxResults($maxResults)
            ->setCacheable(false)
            ->getScalarResult();

        $keys = [];

        foreach ($rows as $row) {
            if (!is_array($row) || count($row) !== $width) {
                throw new LogicException(sprintf('Cannot key the page of [%s]: the limit walker answered a row of %d value(s) where the %d of its identifier were selected.', $recordClass, is_array($row) ? count($row) : 1, $width));
            }

            $columns = [];

            foreach (array_values($row) as $index => $value) {
                $columns[self::KEY_ALIAS . '_' . $index] = $value;
            }

            $keys[] = $selection->keyOfHydrated($columns);
        }

        return $keys;
    }

    /**
     * Swaps every `ORDER BY <root>.<association over one join column>` item for
     * a hidden `IDENTITY()` result variable ordered by its alias, on every
     * platform. Runs on the identifier step's clone alone, so the record step
     * keeps the builder's own order.
     *
     * @param class-string $recordClass
     */
    private static function orderAssociationsByHiddenIdentity(QueryBuilder $query, string $rootAlias, string $recordClass): void
    {
        /** @var list<OrderBy> $orderings */
        $orderings = $query->getDQLPart('orderBy');

        $metadata = $query->getEntityManager()->getClassMetadata($recordClass);
        $selects = [];
        $items = [];

        foreach ($orderings as $ordering) {
            foreach ($ordering->getParts() as $part) {
                $pieces = explode(' ', $part, 2);
                $expression = $pieces[0];
                $field = str_starts_with($expression, "{$rootAlias}.") ? mb_substr($expression, mb_strlen($rootAlias) + 1) : '';

                if ($metadata->isAssociationWithSingleJoinColumn($field)) {
                    $alias = self::ORDER_ALIAS . count($selects);
                    $selects[] = sprintf('IDENTITY(%s) AS HIDDEN %s', $expression, $alias);
                    $expression = $alias;
                }

                $items[] = [$expression, $pieces[1] ?? 'ASC'];
            }
        }

        if ($selects === []) {
            return;
        }

        $query->resetDQLPart('orderBy');

        foreach ($selects as $select) {
            $query->addSelect($select);
        }

        foreach ($items as [$expression, $direction]) {
            $query->addOrderBy($expression, SortDirections::from($direction));
        }
    }
}
