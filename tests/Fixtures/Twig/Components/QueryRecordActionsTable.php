<?php

declare(strict_types=1);

namespace Sylament\Tables\Tests\Fixtures\Twig\Components;

use Doctrine\ORM\EntityManagerInterface;
use Sylament\Actions\Action;
use Sylament\Actions\Concerns\HasActions;
use Sylament\Actions\Contracts\InteractsWithActions;
use Sylament\Tables\Columns\TextColumn;
use Sylament\Tables\Concerns\HasTable;
use Sylament\Tables\Contracts\InteractsWithTable;
use Sylament\Tables\Table;
use Sylament\Tables\Tests\Fixtures\Doctrine\Entity\Song;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * The query-lane counterpart of `RecordActionsTable`, over a real database: its
 * one record action reads the host's records *before* it writes, so the write
 * lands behind a populated records cache. The confirmation modal is what keeps
 * the write in the `callMountedAction()` request — a modal-less action runs
 * during the mount, whose render is a request of its own.
 */
#[AsLiveComponent(name: 'QueryRecordActionsTable', template: '@TablesFixtures/components/QueryRecordActionsTable.html.twig')]
class QueryRecordActionsTable implements InteractsWithActions, InteractsWithTable
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
            ->recordActions([
                Action::make('duplicate')
                    ->requiresConfirmation()
                    ->action(function (Song $record): void {
                        $this->getTableRecords();

                        $this->entityManager->persist(new Song($record->getId() + 100, $record->getTitle() . ' (copy)', $record->getPlays()));
                        $this->entityManager->flush();
                    }),
            ]);
    }
}
