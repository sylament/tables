<?php

declare(strict_types=1);

namespace Sylament\Tables\Tests\Fixtures\Doctrine\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class Song
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column]
        private int $id,
        #[ORM\Column]
        private string $title,
        #[ORM\Column]
        private int $plays,
        #[ORM\ManyToOne(targetEntity: Artist::class, inversedBy: 'songs')]
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

    public function getPlays(): int
    {
        return $this->plays;
    }

    public function getArtist(): ?Artist
    {
        return $this->artist;
    }
}
