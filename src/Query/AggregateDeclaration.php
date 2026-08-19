<?php

declare(strict_types=1);

namespace Sylament\Tables\Query;

use Closure;

/**
 * One relationship aggregate a column declares: the SQL function to apply
 * ({@see $function}), the dotted association path to apply it over
 * ({@see $relationshipPath}), the field it reads ({@see $column}, null for a
 * count), the still-unevaluated closure narrowing the aggregated set
 * ({@see $scope}, null when the declaration carried none), and whether the
 * scalar result is a boolean rather than a number ({@see $castToBool}, true
 * only for an existence check, which is a count read as a flag).
 */
final readonly class AggregateDeclaration
{
    public function __construct(
        public string $function,
        public string $relationshipPath,
        public ?string $column,
        public ?Closure $scope,
        public bool $castToBool,
    ) {
    }
}
