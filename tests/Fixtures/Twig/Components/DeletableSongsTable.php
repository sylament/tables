<?php

declare(strict_types=1);

namespace Sylament\Tables\Tests\Fixtures\Twig\Components;

use Doctrine\ORM\EntityManagerInterface;
use Sylament\Actions\Concerns\HasActions;
use Sylament\Actions\Contracts\InteractsWithActions;
use Sylament\Actions\DeleteAction;
use Sylament\Tables\Columns\TextColumn;
use Sylament\Tables\Concerns\HasTable;
use Sylament\Tables\Contracts\InteractsWithTable;
use Sylament\Tables\Table;
use Sylament\Tables\Tests\Fixtures\Doctrine\Entity\Song;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * The actions↔tables seam under a real database: a query-lane table whose only
 * row action is the prebuilt `DeleteAction`, so a confirmed mount deletes
 * through Doctrine rather than through a closure the fixture wrote itself. The
 * entity manager is a container service (not built per instance, as
 * `FiltersTable` does) because the delete has to survive into the next request.
 */
#[AsLiveComponent(name: 'DeletableSongsTable', template: '@TablesFixtures/components/DeletableSongsTable.html.twig')]
class DeletableSongsTable implements InteractsWithActions, InteractsWithTable
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
            ->query($this->entityManager->createQueryBuilder()->select('song')->from(Song::class, 'song'))
            ->columns([TextColumn::make('title')])
            ->recordActions([DeleteAction::make()]);
    }
}
