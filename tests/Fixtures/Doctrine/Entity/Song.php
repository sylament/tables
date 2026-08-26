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

    /**
     * The owning side of the inverse many-to-many Artist reads back, so a
     * relationship over Artist::$collaborations is an inverse-side many-to-many
     * — the shape whose mapping still names a `mappedBy`.
     *
     * @var Collection<int, Artist>
     */
    #[ORM\ManyToMany(targetEntity: Artist::class, inversedBy: 'collaborations')]
    private Collection $collaborators;

    public function __construct(
        #[ORM\Id]
        #[ORM\Column]
        private int $id = 0,
        #[ORM\Column]
        private string $title = '',
        #[ORM\Column]
        private int $plays = 0,
        #[ORM\ManyToOne(targetEntity: Artist::class, inversedBy: 'songs')]
        private ?Artist $artist = null,
        #[ORM\Column(nullable: true)]
        private ?bool $isFeatured = null
    ) {
        $this->genres = new ArrayCollection();
        $this->collaborators = new ArrayCollection();
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

    public function addGenre(Genre $genre): static
    {
        if (!$this->genres->contains($genre)) {
            $this->genres->add($genre);
        }

        return $this;
    }

    public function removeGenre(Genre $genre): static
    {
        $this->genres->removeElement($genre);

        return $this;
    }

    public function setId(int $id): static
    {
        $this->id = $id;

        return $this;
    }

    public function setTitle(string $title): static
    {
        $this->title = $title;

        return $this;
    }

    public function setPlays(int $plays): static
    {
        $this->plays = $plays;

        return $this;
    }

    public function setArtist(?Artist $artist): static
    {
        $this->artist = $artist;

        return $this;
    }
}
