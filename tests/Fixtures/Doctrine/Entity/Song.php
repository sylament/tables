<?php

declare(strict_types=1);

namespace Sylament\Tables\Tests\Fixtures\Doctrine\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class Song
{
    /**
     * @var Collection<int, Genre>
     */
    #[ORM\ManyToMany(targetEntity: Genre::class)]
    private Collection $genres;

    public function __construct(
        #[ORM\Id]
        #[ORM\Column]
        private int $id,
        #[ORM\Column]
        private string $title,
        #[ORM\Column]
        private int $plays,
        #[ORM\ManyToOne(targetEntity: Artist::class, inversedBy: 'songs')]
        private ?Artist $artist = null,
        #[ORM\Column(nullable: true)]
        private ?bool $isFeatured = null
    ) {
        $this->genres = new ArrayCollection();
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

    public function isFeatured(): ?bool
    {
        return $this->isFeatured;
    }

    /**
     * @return Collection<int, Genre>
     */
    public function getGenres(): Collection
    {
        return $this->genres;
    }
}
