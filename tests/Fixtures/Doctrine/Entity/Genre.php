<?php

declare(strict_types=1);

namespace Sylament\Tables\Tests\Fixtures\Doctrine\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * The far side of a many-to-many, so a path can cross a to-many hop. It
 * constructs with no arguments and writes through setters, so a `CreateAction`
 * can map a modal's state onto a new one — the rig assigns identifiers itself,
 * so the form supplies the id.
 */
#[ORM\Entity]
class Genre
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column]
        private int $id = 0,
        #[ORM\Column]
        private string $name = ''
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

    public function setId(int $id): static
    {
        $this->id = $id;

        return $this;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }
}
