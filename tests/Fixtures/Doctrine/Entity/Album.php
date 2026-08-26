<?php

declare(strict_types=1);

namespace Sylament\Tables\Tests\Fixtures\Doctrine\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * The orphan-removal arm of {@see Artist}: a one-to-many whose owner deletes
 * what leaves the collection, so dissociating from it is refused.
 */
#[ORM\Entity]
class Album
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column]
        private int $id,
        #[ORM\Column]
        private string $title,
        #[ORM\ManyToOne(targetEntity: Artist::class, inversedBy: 'albums')]
        private ?Artist $artist = null
    ) {
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getArtist(): ?Artist
    {
        return $this->artist;
    }

    public function setArtist(?Artist $artist): static
    {
        $this->artist = $artist;

        return $this;
    }
}
