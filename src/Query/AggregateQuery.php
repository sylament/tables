<?php

declare(strict_types=1);

namespace Sylament\Tables\Query;

use Closure;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use LogicException;
use Sylament\Schemas\Identity\IdentifierConstraint;
use Sylament\Schemas\Identity\KeySelection;

final class AggregateQuery
{
    /**
     * One batched read of a declared aggregate for a whole page: a grouped
     * scalar select over the record class, joined along the declared path and
     * narrowed to the page's keys, never touching the page query itself. The
     * key projection, the grouping and the narrowing each run per identifier
     * field, so a key of any width addresses the row it names.
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
     * The keys are chunked. The scope runs once, on the pristine builder, and
     * each chunk narrows a copy of that builder under the one parameter base.
     *
     * @param class-string $recordClass
     * @param list<string> $keys
     *
     * @return array<string, int|float|string|null> record key ⇒ raw value
     *
     * @throws LogicException when the batch reads back a row the select it
     *                        authored cannot produce
     */
    public static function fetch(EntityManagerInterface $entityManager, string $recordClass, AggregateDeclaration $declaration, array $keys): array
    {
        $query = $entityManager->createQueryBuilder()->from($recordClass, 'sy_r');
        $alias = 'sy_r';

        foreach (explode('.', $declaration->relationshipPath) as $index => $segment) {
            $next = "sy_t{$index}";

            $query->join("{$alias}.{$segment}", $next);

            $alias = $next;
        }

        $expression = $declaration->column === null ? $alias : "{$alias}.{$declaration->column}";
        $selection = IdentifierConstraint::select($query, 'sy_r', $recordClass);

        $query
            ->select(...$selection->expressions)
            ->addSelect("{$declaration->function}({$expression}) AS sy_value")
            ->groupBy(...IdentifierConstraint::groupBy($query, 'sy_r', $recordClass));

        if ($declaration->scope instanceof Closure) {
            ($declaration->scope)($query, $alias);
        }

        $values = [];

        foreach (IdentifierConstraint::chunk($entityManager, $recordClass, $keys) as $chunk) {
            $chunkQuery = clone $query;

            $chunkQuery->andWhere(IdentifierConstraint::in($chunkQuery, 'sy_r', $recordClass, $chunk, 'sylament_agg_keys'));

            // The chunks are disjoint, and `array_merge()` would renumber the
            // integer array key a numeric record key becomes.
            $values += self::readValues($chunkQuery, $recordClass, $selection);
        }

        return $values;
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
     * correlated to the outer row on every identifier field rather than to a
     * key list. What an empty related set reads as is the aggregate's own
     * answer here: a count over no rows is 0, every other function is null, so
     * the two lanes agree with the defaults the cells fill in.
     *
     * Whatever the scope bound onto the sub-builder is transferred to the outer
     * query, which is the one executed — the subselect travels as DQL text and
     * its parameter bag does not come with it.
     *
     * @param class-string $recordClass
     *
     * @return string the hidden alias the caller orders by
     *
     * @throws LogicException when the table query names no root alias to
     *                        correlate the subselect against
     */
    public static function addHiddenSortSelect(QueryBuilder $query, string $recordClass, AggregateDeclaration $declaration): string
    {
        $entityManager = $query->getEntityManager();

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

        $subQuery->andWhere(IdentifierConstraint::correlate($subQuery, $subAlias, $recordClass, $rootAlias));

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
     * The batch read back as record key ⇒ value, each row re-keyed through the
     * selection that projected it, so the key matches the one the page minted
     * from the record. The two must agree, or the store is filled under keys no
     * cell ever asks for.
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
     * @throws LogicException when a row is not the scalar shape that was selected
     */
    private static function readValues(QueryBuilder $query, string $recordClass, KeySelection $selection): array
    {
        $values = [];

        foreach ($query->getQuery()->getArrayResult() as $row) {
            if (!is_array($row)) {
                throw new LogicException(sprintf('The aggregate batch over [%s] returned a %s where the selected key and value were expected.', $recordClass, get_debug_type($row)));
            }

            $value = $row['sy_value'] ?? null;

            if ($value !== null && !is_int($value) && !is_float($value) && !is_string($value)) {
                throw new LogicException(sprintf('The aggregate batch over [%s] returned a %s value, which no cell can carry.', $recordClass, get_debug_type($value)));
            }

            $values[$selection->keyOfHydrated($row)] = $value;
        }

        return $values;
    }
}
