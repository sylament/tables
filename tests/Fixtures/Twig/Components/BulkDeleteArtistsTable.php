<?php

declare(strict_types=1);

namespace Sylament\Tables\Tests\Fixtures\Twig\Components;

use Doctrine\ORM\EntityManagerInterface;
use Sylament\Actions\Concerns\HasActions;
use Sylament\Actions\Contracts\InteractsWithActions;
use Sylament\Actions\DeleteBulkAction;
use Sylament\Tables\Columns\TextColumn;
use Sylament\Tables\Concerns\HasTable;
use Sylament\Tables\Contracts\InteractsWithTable;
use Sylament\Tables\Table;
use Sylament\Tables\Tests\Fixtures\Doctrine\Entity\Artist;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * The by-key delete over a record class rows *point at*: `Song.artist_id`
 * references it with no cascade, so a seeded song plus SQLite's foreign-key
 * enforcement makes the DQL `DELETE` fail for real, which is the only way to
 * reach {@see DeleteBulkAction}'s by-key catch.
 */
#[AsLiveComponent(name: 'BulkDeleteArtistsTable', template: '@TablesFixtures/components/BulkDeleteArtistsTable.html.twig')]
class BulkDeleteArtistsTable implements InteractsWithActions, InteractsWithTable
{
    use DefaultActionTrait;
    use HasActions;
    use HasTable;

    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public function table(Table $table): Table
    {
        return $table
            ->query($this->entityManager->createQueryBuilder()->select('artist')->from(Artist::class, 'artist'))
            ->columns([TextColumn::make('name')])
            ->toolbarActions([DeleteBulkAction::make()->fetchSelectedRecords(false)])
            ->paginated([10]);
    }
}
