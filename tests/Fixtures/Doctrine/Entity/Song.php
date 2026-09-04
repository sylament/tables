<?php

declare(strict_types=1);

namespace Sylament\Tables\Tests\Fixtures\Doctrine\Entity;

use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Sylament\Tables\Tests\Fixtures\Records\Format;

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
        private ?bool $isFeatured = null,
        /**
         * The checkbox column's own target, so the toggle and the checkbox can sit
         * on one table: `columns()` keys by name, and two columns sharing one would
         * drop the first.
         */
        #[ORM\Column(nullable: true)]
        private ?bool $isExplicit = null,
        /**
         * The select column's nullable target: a blank placeholder writes null here.
         */
        #[ORM\Column(nullable: true)]
        private ?string $mood = null,
        /**
         * The select column's non-nullable target, which is what makes a blank
         * placeholder a validation refusal rather than a type error.
         */
        #[ORM\Column]
        private string $tempo = 'medium',
        /**
         * The reorder lane's position column — a plain writable field the drag
         * write assigns 1..N onto.
         */
        #[ORM\Column(nullable: true)]
        private ?int $position = null,
        /**
         * A `date()` group's datetime target: the day is computed in PHP and
         * scoped as a half-open range, so an instant inside the day still
         * matches the day key.
         */
        #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
        private ?DateTimeImmutable $releasedAt = null,
        /**
         * A `date()` group's date target, whose own DBAL type is what the range
         * parameters have to be bound as — an untyped datetime literal compares
         * as text against a date column and answers the neighbouring day.
         */
        #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
        private ?DateTimeImmutable $chartedOn = null,
        /**
         * The select column's enum-typed target: a posted backing string only
         * reaches it through the column's state cast.
         */
        #[ORM\Column(nullable: true, enumType: Format::class)]
        private ?Format $format = null,
        /**
         * A field named after one of Filament's 26 reserved JavaScript array
         * property names, and — unlike `length` — neither a SQL keyword nor a
         * DQL function, so a column can carry it and prove the reserved list is
         * unnecessary here.
         */
        #[ORM\Column(nullable: true)]
        private ?int $entries = null
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

    public function setIsFeatured(?bool $isFeatured): static
    {
        $this->isFeatured = $isFeatured;

        return $this;
    }

    public function getIsExplicit(): ?bool
    {
        return $this->isExplicit;
    }

    public function setIsExplicit(?bool $isExplicit): static
    {
        $this->isExplicit = $isExplicit;

        return $this;
    }

    public function getMood(): ?string
    {
        return $this->mood;
    }

    public function setMood(?string $mood): static
    {
        $this->mood = $mood;

        return $this;
    }

    public function getTempo(): string
    {
        return $this->tempo;
    }

    public function setTempo(string $tempo): static
    {
        $this->tempo = $tempo;

        return $this;
    }

    public function getFormat(): ?Format
    {
        return $this->format;
    }

    public function setFormat(?Format $format): static
    {
        $this->format = $format;

        return $this;
    }

    public function getPosition(): ?int
    {
        return $this->position;
    }

    public function getEntries(): ?int
    {
        return $this->entries;
    }

    public function setPosition(?int $position): static
    {
        $this->position = $position;

        return $this;
    }

    public function getReleasedAt(): ?DateTimeImmutable
    {
        return $this->releasedAt;
    }

    public function setReleasedAt(?DateTimeImmutable $releasedAt): static
    {
        $this->releasedAt = $releasedAt;

        return $this;
    }

    public function getChartedOn(): ?DateTimeImmutable
    {
        return $this->chartedOn;
    }

    public function setChartedOn(?DateTimeImmutable $chartedOn): static
    {
        $this->chartedOn = $chartedOn;

        return $this;
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
