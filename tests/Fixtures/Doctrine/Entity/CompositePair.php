<?php

declare(strict_types=1);

namespace Sylament\Tables\Tests\Fixtures\Doctrine\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * A record with a composite identifier, so a single-column key assumption fails
 * against it.
 */
#[ORM\Entity]
class CompositePair
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column]
        private int $a,
        #[ORM\Id]
        #[ORM\Column]
        private int $b,
        #[ORM\Column]
        private string $label
    ) {
    }

    public function getA(): int
    {
        return $this->a;
    }

    public function getB(): int
    {
        return $this->b;
    }

    public function getLabel(): string
    {
        return $this->label;
    }
}
