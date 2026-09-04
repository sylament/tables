<?php

declare(strict_types=1);

namespace Sylament\Tables\Tests\Fixtures\Doctrine\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class Artist
{
    /**
     * @var Collection<int, Song>
     */
    #[ORM\OneToMany(targetEntity: Song::class, mappedBy: 'artist')]
    #[ORM\OrderBy(['title' => 'ASC'])]
    private Collection $songs;

    /**
     * Ordered **descending**, the one mapping order in either fixture repo that
     * is: it is what tells the default sort's direction map apart from a
     * hardcoded `asc`.
     *
     * @var Collection<int, Album>
     */
    #[ORM\OneToMany(targetEntity: Album::class, mappedBy: 'artist', orphanRemoval: true)]
    #[ORM\OrderBy(['title' => 'DESC'])]
    private Collection $albums;

    /**
     * The inverse side of a many-to-many, which Doctrine gives a `mappedBy` just
     * as it does an inverse one-to-many — the shape a null test for that field
     * would wave through.
     *
     * @var Collection<int, Song>
     */
    #[ORM\ManyToMany(targetEntity: Song::class, mappedBy: 'collaborators')]
    private Collection $collaborations;

    public function __construct(
        #[ORM\Id]
        #[ORM\Column]
        private int $id,
        #[ORM\Column]
        private string $name,
        #[ORM\ManyToOne(targetEntity: Label::class)]
        private ?Label $label = null,
        #[ORM\Embedded(class: Address::class)]
        private Address $address = new Address()
    ) {
        $this->songs = new ArrayCollection();
        $this->albums = new ArrayCollection();
        $this->collaborations = new ArrayCollection();
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getLabel(): ?Label
    {
        return $this->label;
    }

    public function getAddress(): Address
    {
        return $this->address;
    }

    /**
     * @return Collection<int, Song>
     */
    public function getSongs(): Collection
    {
        return $this->songs;
    }

    /**
     * @return Collection<int, Album>
     */
    public function getAlbums(): Collection
    {
        return $this->albums;
    }

    /**
     * @return Collection<int, Song>
     */
    public function getCollaborations(): Collection
    {
        return $this->collaborations;
    }

    public function addSong(Song $song): static
    {
        if (!$this->songs->contains($song)) {
            $this->songs->add($song);
        }

        $song->setArtist($this);

        return $this;
    }

    public function removeSong(Song $song): static
    {
        $this->songs->removeElement($song);

        if ($song->getArtist() === $this) {
            $song->setArtist(null);
        }

        return $this;
    }
}
