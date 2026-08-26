<?php

declare(strict_types=1);

namespace Sylament\Tables\Tests\Fixtures\Twig\Components;

use Doctrine\ORM\EntityManagerInterface;
use Sylament\Actions\Concerns\HasActions;
use Sylament\Actions\Contracts\InteractsWithActions;
use Sylament\Actions\DeleteAction;
use Sylament\Actions\DeleteBulkAction;
use Sylament\Schemas\State\Relationship;
use Sylament\Tables\Columns\TextColumn;
use Sylament\Tables\Concerns\HasTable;
use Sylament\Tables\Contracts\InteractsWithTable;
use Sylament\Tables\Table;
use Sylament\Tables\Tests\Fixtures\Doctrine\Entity\Artist;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * The one-to-many relation-scoped host: one artist's songs, carrying the delete
 * pair — a deleted `Song` owns its own join rows, so the deletes land here
 * rather than on {@see SongGenresTable}. `#[ORM\OrderBy]` on `Artist::$songs`
 * is what makes this the mapping-order fixture too.
 */
#[AsLiveComponent(name: 'ArtistSongsTable', template: '@TablesFixtures/components/ArtistSongsTable.html.twig')]
class ArtistSongsTable implements InteractsWithActions, InteractsWithTable
{
    use DefaultActionTrait;
    use HasActions;
    use HasTable;

    #[LiveProp]
    public int $artistId = 1;

    #[LiveProp]
    public bool $fetchesRecords = true;

    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public function table(Table $table): Table
    {
        return $table
            ->relationship(function (): ?Relationship {
                $artist = $this->entityManager->find(Artist::class, $this->artistId);

                return $artist instanceof Artist ? new Relationship($artist, 'songs') : null;
            })
            ->columns([
                TextColumn::make('title')->sortable(),
                TextColumn::make('plays')->sortable(),
            ])
            ->recordActions([DeleteAction::make()])
            ->toolbarActions([DeleteBulkAction::make()->fetchSelectedRecords($this->fetchesRecords)])
            ->paginated([10]);
    }
}
