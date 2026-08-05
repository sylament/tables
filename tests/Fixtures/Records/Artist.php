<?php

declare(strict_types=1);

namespace Sylament\Tables\Tests\Fixtures\Records;

final readonly class Artist
{
    public function __construct(
        public string $name,
    ) {
    }
}
