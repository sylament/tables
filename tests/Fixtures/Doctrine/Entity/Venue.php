<?php

declare(strict_types=1);

namespace Sylament\Tables\Tests\Fixtures\Doctrine\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Sylament\Tables\Tests\Fixtures\Doctrine\Handle;
use Sylament\Tables\Tests\Fixtures\Doctrine\HandleType;

/**
 * A record keyed by an object identifier — the shape a Uuid or Ulid primary key
 * takes — so a read that assumes a scalar identifier is caught.
 */
#[ORM\Entity]
class Venue
{
    /**
     * @var Collection<int, Gig>
     */
    #[ORM\OneToMany(targetEntity: Gig::class, mappedBy: 'venue')]
    private Collection $gigs;

    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: HandleType::NAME)]
        private Handle $handle,
        #[ORM\Column]
        private string $name
    ) {
        $this->gigs = new ArrayCollection();
    }

    public function getHandle(): Handle
    {
        return $this->handle;
    }

    public function getName(): string
    {
        return $this->name;
    }

    /**
     * @return Collection<int, Gig>
     */
    public function getGigs(): Collection
    {
        return $this->gigs;
    }
}
