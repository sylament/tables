<?php

declare(strict_types=1);

namespace Sylament\Tables\Tests\Fixtures\Doctrine\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * A record whose identifier is not named `id`, so a key or sort built on the
 * identifier cannot assume the name.
 */
#[ORM\Entity]
class Book
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column]
        private string $code,
        #[ORM\Column]
        private string $title
    ) {
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function getTitle(): string
    {
        return $this->title;
    }
}
