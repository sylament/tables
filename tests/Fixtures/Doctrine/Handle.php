<?php

declare(strict_types=1);

namespace Sylament\Tables\Tests\Fixtures\Doctrine;

use Stringable;

/**
 * A string-backed identifier value object, standing in for the Uuid/Ulid class
 * a real application keys its entities by: hydration hands one back as an
 * object, so anything reading an identifier off a result row meets it unwrapped.
 */
final readonly class Handle implements Stringable
{
    public function __construct(
        private string $value,
    ) {
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
