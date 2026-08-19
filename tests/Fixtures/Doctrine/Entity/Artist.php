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
    private Collection $songs;

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
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
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
}
