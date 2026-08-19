<?php

declare(strict_types=1);

namespace Sylament\Tables\Query;

/**
 * One association step of a relation path: the property to join, the entity it
 * lands on, and whether it lands on a collection. The cardinality flag is for
 * the callers to consult, never for `RelationJoiner`, which left-joins every hop
 * alike: a to-many step multiplies rows, so sorting refuses a path carrying one
 * before it joins anything, search constrains with a correlated `EXISTS` instead
 * of joining at all, and eager loading skips the chain. Nothing downstream of a
 * join may assume the joiner filtered a hop out.
 */
final readonly class Hop
{
    /**
     * @param class-string $targetClass
     */
    public function __construct(
        public string $property,
        public string $targetClass,
        public bool $isToMany,
    ) {
    }
}
