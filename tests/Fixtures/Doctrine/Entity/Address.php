<?php

declare(strict_types=1);

namespace Sylament\Tables\Tests\Fixtures\Doctrine\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * An embeddable, so a dotted path can name a field without crossing a relation.
 */
#[ORM\Embeddable]
class Address
{
    public function __construct(
        #[ORM\Column]
        private string $street = ''
    ) {
    }

    public function getStreet(): string
    {
        return $this->street;
    }
}
