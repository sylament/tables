<?php

declare(strict_types=1);

namespace Sylament\Tables\Tests\Fixtures\Records;

final readonly class Song
{
    public function __construct(
        public string $title,
        public string $album,
        public ?Artist $artist = null,
        public ?int $id = null,
    ) {
    }
}
