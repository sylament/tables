<?php

declare(strict_types=1);

namespace Sylament\Tables\Query;

/**
 * A dotted path split against the Doctrine mapping: the association steps to
 * cross ({@see $hops}, empty for a path that never leaves the root) and the
 * field remainder to read at the end, itself still dotted when it names an
 * embeddable field.
 */
final readonly class ResolvedRelationPath
{
    /**
     * @param list<Hop> $hops
     */
    public function __construct(
        public array $hops,
        public string $fieldPath,
    ) {
    }

    public function isRelationPath(): bool
    {
        return $this->hops !== [];
    }

    public function hasToManyHop(): bool
    {
        return array_any($this->hops, static fn (Hop $hop): bool => $hop->isToMany);
    }
}
