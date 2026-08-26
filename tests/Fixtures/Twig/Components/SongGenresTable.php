<?php

declare(strict_types=1);

namespace Sylament\Tables\Tests\Fixtures\Twig\Components;

use Doctrine\ORM\EntityManagerInterface;
use Sylament\Actions\Concerns\HasActions;
use Sylament\Actions\Contracts\InteractsWithActions;
use Sylament\Schemas\State\Relationship;
use Sylament\Tables\Columns\TextColumn;
use Sylament\Tables\Concerns\HasTable;
use Sylament\Tables\Contracts\InteractsWithTable;
use Sylament\Tables\Table;
use Sylament\Tables\Tests\Fixtures\Doctrine\Entity\Song;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * The many-to-many relation-scoped host: one song's genres, over the
 * container-wide entity manager so a table read spans two live requests. It
 * carries no actions on purpose — `Song.genres` is mapped on `Song`, so
 * deleting a `Genre` would leave the join rows behind in a rig whose SQLite
 * enforces no foreign keys.
 */
#[AsLiveComponent(name: 'SongGenresTable', template: '@TablesFixtures/components/SongGenresTable.html.twig')]
class SongGenresTable implements InteractsWithActions, InteractsWithTable
{
    use DefaultActionTrait;
    use HasActions;
    use HasTable;

    #[LiveProp]
    public int $songId = 1;

    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public function table(Table $table): Table
    {
        return $table
            ->relationship(function (): ?Relationship {
                $song = $this->entityManager->find(Song::class, $this->songId);

                return $song instanceof Song ? new Relationship($song, 'genres') : null;
            })
            ->columns([TextColumn::make('name')->searchable()->sortable()])
            ->paginated([2, 10]);
    }
}
