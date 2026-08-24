<?php

declare(strict_types=1);

namespace Sylament\Tables\Tests\Fixtures\Twig\Components;

use Sylament\Actions\Action;
use Sylament\Actions\BulkAction;
use Sylament\Actions\Concerns\HasActions;
use Sylament\Actions\Contracts\InteractsWithActions;
use Sylament\Tables\Columns\TextColumn;
use Sylament\Tables\Concerns\HasTable;
use Sylament\Tables\Contracts\InteractsWithTable;
use Sylament\Tables\Table;
use Sylament\Tables\Tests\Fixtures\Records\Song;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * The resolution host: one name — `archive` — registered in **both** of the
 * table's action maps, so which map a mount read is decidable from the resolved
 * action alone. Every action confirms, so a mount stops at its modal and leaves
 * the resolved action readable.
 */
#[AsLiveComponent(name: 'BulkResolutionTable', template: '@TablesFixtures/components/BulkResolutionTable.html.twig')]
class BulkResolutionTable implements InteractsWithActions, InteractsWithTable
{
    use DefaultActionTrait;
    use HasActions;
    use HasTable;

    public function table(Table $table): Table
    {
        return $table
            ->records(fn (): array => [
                new Song('Airbag', 'OK Computer', id: 1),
                new Song('Let Down', 'OK Computer', id: 2),
            ])
            ->columns([TextColumn::make('title')])
            ->recordActions([
                Action::make('archive')
                    ->requiresConfirmation()
                    ->modalHeading('Record archive')
                    ->action(fn (): null => null),
            ])
            ->toolbarActions([
                BulkAction::make('archive')
                    ->requiresConfirmation()
                    ->modalHeading('Bulk archive')
                    ->action(fn (): null => null),
            ]);
    }
}
