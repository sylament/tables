<?php

declare(strict_types=1);

namespace Sylament\Tables\Tests\Fixtures\Doctrine\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * A second to-one association past {@see Artist}, so a path can chain two hops.
 */
#[ORM\Entity]
class Label
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column]
        private int $id,
        #[ORM\Column]
        private string $name
    ) {
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }
}
