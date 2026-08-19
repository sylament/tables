<?php

declare(strict_types=1);

namespace Sylament\Tables\Query;

use Closure;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\MappingException;
use Doctrine\ORM\QueryBuilder;
use LogicException;
use Stringable;

final class AggregateQuery
{
    /**
     * One batched read of a declared aggregate for a whole page: a grouped
     * scalar select over the record class, joined along the declared path and
     * narrowed to the page's keys, never touching the page query itself.
     *
     * The joins are inner, so a key whose related set is empty produces no
     * group and is absent from the result rather than zero — what a missing
     * key reads as is the caller's decision, not this one's. The scope closure
     * arrives already bound, and is handed the builder and the alias of the
     * path's last hop so it can narrow the aggregated set.
     *
     * The key narrowing is added *after* the scope has run, because Doctrine's
     * `where()` replaces where Eloquent's adds: a scope written with the
     * Filament reflex would otherwise drop the narrowing this batch depends on.
     *
     * @param class-string     $recordClass
     * @param list<int|string> $keys
     *
     * @return array<string, int|float|string|null> record key ⇒ raw value
     *
     * @throws LogicException when the record class carries a composite
     *                        identifier, which no single key column addresses
     */
    public static function fetch(EntityManagerInterface $entityManager, string $recordClass, AggregateDeclaration $declaration, array $keys): array
    {
        try {
            $identifier = $entityManager->getClassMetadata($recordClass)->getSingleIdentifierFieldName();
        } catch (MappingException) {
            throw new LogicException(sprintf('Cannot aggregate [%s]: the record class [%s] has a composite identifier.', $declaration->relationshipPath, $recordClass));
        }

        $query = $entityManager->createQueryBuilder()->from($recordClass, 'sy_r');
        $alias = 'sy_r';

        foreach (explode('.', $declaration->relationshipPath) as $index => $segment) {
            $next = "sy_t{$index}";

            $query->join("{$alias}.{$segment}", $next);

            $alias = $next;
        }

        $expression = $declaration->column === null ? $alias : "{$alias}.{$declaration->column}";

        $query
            ->select("sy_r.{$identifier} AS sy_key", "{$declaration->function}({$expression}) AS sy_value")
            ->groupBy("sy_r.{$identifier}");

        if ($declaration->scope instanceof Closure) {
            ($declaration->scope)($query, $alias);
        }

        $query
            ->andWhere("sy_r.{$identifier} IN (:sylament_agg_keys)")
            ->setParameter('sylament_agg_keys', $keys);

        return self::readValues($query, $recordClass);
    }

    /**
     * The same declared aggregate as a correlated subselect on the page query,
     * selected `AS HIDDEN` so the ordering can name it while hydration never
     * sees it: the page's rows stay the plain entities they were, and only the
     * sorted clone carries the select.
     *
     * The subselect is built the way {@see fetch()} builds its batch — inner
     * joins along the declared path, the scope closure arriving already bound
     * and handed the builder with the alias of the path's last hop, and the
     * correlation added after it has run so a `where()` cannot replace it —
     * correlated to the outer row by identifier rather than to a key list. What
     * an empty related set reads as is the aggregate's own answer here: a count
     * over no rows is 0, every other function is null, so the two lanes agree
     * with the defaults the cells fill in.
     *
     * Whatever the scope bound onto the sub-builder is transferred to the outer
     * query, which is the one executed — the subselect travels as DQL text and
     * its parameter bag does not come with it.
     *
     * @param class-string $recordClass
     *
     * @return string the hidden alias the caller orders by
     *
     * @throws LogicException when the record class carries a composite
     *                        identifier, which no single column correlates
     */
    public static function addHiddenSortSelect(QueryBuilder $query, string $recordClass, AggregateDeclaration $declaration): string
    {
        $entityManager = $query->getEntityManager();

        try {
            $identifier = $entityManager->getClassMetadata($recordClass)->getSingleIdentifierFieldName();
        } catch (MappingException) {
            throw new LogicException(sprintf('Cannot sort by an aggregate over [%s]: the record class [%s] has a composite identifier.', $declaration->relationshipPath, $recordClass));
        }

        $rootAlias = $query->getRootAliases()[0]
            ?? throw new LogicException(sprintf('Cannot sort by an aggregate over [%s]: the table query has no root alias to correlate it against.', $declaration->relationshipPath));

        $selectPart = $query->getDQLPart('select');
        $seed = count(is_array($selectPart) ? $selectPart : []);
        $subAlias = "sy_ag{$seed}";
        $alias = "sy_agg{$seed}";

        $subQuery = $entityManager->createQueryBuilder()->from($recordClass, $subAlias);
        $joinAlias = $subAlias;

        foreach (explode('.', $declaration->relationshipPath) as $index => $segment) {
            $next = "{$subAlias}_{$index}";

            $subQuery->join("{$joinAlias}.{$segment}", $next);

            $joinAlias = $next;
        }

        $expression = $declaration->column === null ? $joinAlias : "{$joinAlias}.{$declaration->column}";

        $subQuery->select("{$declaration->function}({$expression})");

        if ($declaration->scope instanceof Closure) {
            ($declaration->scope)($subQuery, $joinAlias);
        }

        $subQuery->andWhere("{$subAlias}.{$identifier} = {$rootAlias}.{$identifier}");

        foreach ($subQuery->getParameters() as $parameter) {
            $type = $parameter->getType();

            if (!$type instanceof ParameterType && !$type instanceof ArrayParameterType && !is_string($type) && !is_int($type)) {
                $type = null;
            }

            $query->setParameter($parameter->getName(), $parameter->getValue(), $type);
        }

        $query->addSelect("({$subQuery->getDQL()}) AS HIDDEN {$alias}");

        return $alias;
    }

    /**
     * The batch read back as record key ⇒ value, with an identifier that
     * hydrated as an object — a Uuid, a Ulid, any string-backed value object —
     * unwrapped to the string the page keyed its records by, the same way
     * `getTableRecordKey()` unwraps it on the page side. The two must agree, or
     * the store is filled under keys no cell ever asks for.
     *
     * Nothing here recovers from a row it cannot read: every shape below is one
     * the select this class authored cannot produce, so meeting one means the
     * query was rewritten out from under it, and a skipped row would surface as
     * a cell quietly reading its default rather than as an error.
     *
     * @param class-string $recordClass
     *
     * @return array<string, int|float|string|null>
     *
     * @throws LogicException when a row is not the scalar pair that was selected
     */
    private static function readValues(QueryBuilder $query, string $recordClass): array
    {
        $values = [];

        foreach ($query->getQuery()->getArrayResult() as $row) {
            if (!is_array($row)) {
                throw new LogicException(sprintf('The aggregate batch over [%s] returned a %s where the selected key and value were expected.', $recordClass, get_debug_type($row)));
            }

            $key = $row['sy_key'] ?? null;
            $value = $row['sy_value'] ?? null;

            if ($key instanceof Stringable) {
                $key = (string) $key;
            }

            if (!is_scalar($key)) {
                throw new LogicException(sprintf('Cannot key the aggregate batch over [%s]: its identifier read back as %s, which is neither scalar nor stringable.', $recordClass, get_debug_type($key)));
            }

            if ($value !== null && !is_int($value) && !is_float($value) && !is_string($value)) {
                throw new LogicException(sprintf('The aggregate batch over [%s] returned a %s value, which no cell can carry.', $recordClass, get_debug_type($value)));
            }

            $values[(string) $key] = $value;
        }

        return $values;
    }
}
