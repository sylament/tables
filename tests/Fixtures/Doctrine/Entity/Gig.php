<?php

declare(strict_types=1);

namespace Sylament\Tables\Tests\Fixtures\Doctrine\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class Gig
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column]
        private int $id,
        #[ORM\Column]
        private string $title,
        #[ORM\Column]
        private int $attendance,
        #[ORM\ManyToOne(targetEntity: Venue::class, inversedBy: 'gigs')]
        #[ORM\JoinColumn(referencedColumnName: 'handle')]
        private ?Venue $venue = null
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

    public function getAttendance(): int
    {
        return $this->attendance;
    }

    public function getVenue(): ?Venue
    {
        return $this->venue;
    }
}
