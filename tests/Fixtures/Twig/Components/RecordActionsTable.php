<?php

declare(strict_types=1);

namespace Sylament\Tables\Tests\Fixtures\Twig\Components;

use Sylament\Actions\Action;
use Sylament\Actions\Concerns\HasActions;
use Sylament\Actions\Contracts\InteractsWithActions;
use Sylament\Tables\Columns\TextColumn;
use Sylament\Tables\Concerns\HasTable;
use Sylament\Tables\Contracts\InteractsWithTable;
use Sylament\Tables\Table;
use Sylament\Tables\Tests\Fixtures\Records\Song;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * A live host wearing both contracts — the shape a table-context mount resolves
 * against. The deletion list is a `LiveProp` so a confirmed delete survives the
 * dehydrate/hydrate round trip and the next render reads the shortened list.
 */
#[AsLiveComponent(name: 'RecordActionsTable', template: '@TablesFixtures/components/RecordActionsTable.html.twig')]
class RecordActionsTable implements InteractsWithActions, InteractsWithTable
{
    use DefaultActionTrait;
    use HasActions;
    use HasTable;

    /**
     * @var list<int>
     */
    #[LiveProp]
    public array $deletedIds = [];

    public function table(Table $table): Table
    {
        return $table
            ->records(fn (): array => array_values(array_filter([
                new Song('Airbag', 'OK Computer', id: 1),
                new Song('Let Down', 'OK Computer', id: 2),
            ], fn (Song $song): bool => !in_array($song->id, $this->deletedIds, true))))
            ->columns([TextColumn::make('title')])
            ->recordActions([
                Action::make('edit')->url(fn (Song $record): string => '/songs/' . $record->id . '/edit'),
                Action::make('delete')
                    ->requiresConfirmation()
                    ->modalHeading(fn (Song $record): string => sprintf('Delete "%s"?', $record->title))
                    ->action(function (Song $record): void {
                        $this->deletedIds[] = $record->id;
                    }),
            ]);
    }
}
