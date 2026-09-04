<?php

declare(strict_types=1);

namespace Sylament\Tables\Tests\Fixtures\Twig\Components;

use Sylament\Actions\Action;
use Sylament\Actions\Concerns\HasActions;
use Sylament\Actions\Contracts\InteractsWithActions;
use Sylament\Tables\Columns\Column;
use Sylament\Tables\Columns\TextColumn;
use Sylament\Tables\Concerns\HasTable;
use Sylament\Tables\Contracts\InteractsWithTable;
use Sylament\Tables\Table;
use Sylament\Tables\Tests\Fixtures\Records\Song;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * A live host whose columns carry all three `action()` arms — a closure the host's
 * own live action evaluates, a method name the string arm wraps, and an `Action`
 * object that lane refuses and a table-context `mountAction` resolves instead.
 * Each call appends to a `LiveProp`, so what the action was handed survives the
 * round trip and the next render reads it.
 *
 * The last two columns are the gate's pair, and neither renders. One is hidden
 * and carries a closure of its own, so the host's hidden-column gate has
 * something to refuse: a name the client can post, an action that would leave a
 * trace, and no way for a rendered page to have offered it. The other is
 * `toggleable()` and hidden by default, so the wire also carries a name the
 * *user* hid — which the gate must still let through, because that is their own
 * preference rather than the developer's visibility.
 *
 * Its columns are reorderable, so every case here runs on a table the column
 * manager has already re-`columns()`ed — the one interaction that could have
 * made the gate resolve a stale column.
 */
#[AsLiveComponent(name: 'ColumnActionsTable', template: '@TablesFixtures/components/ColumnActionsTable.html.twig')]
class ColumnActionsTable implements InteractsWithActions, InteractsWithTable
{
    use DefaultActionTrait;
    use HasActions;
    use HasTable;

    /**
     * @var list<string>
     */
    #[LiveProp]
    public array $calls = [];

    public function table(Table $table): Table
    {
        return $table
            ->records(fn (): array => [
                new Song('Airbag', 'OK Computer', id: 1),
                new Song('Let Down', 'OK Computer', id: 2),
            ])
            ->columns([
                TextColumn::make('title')->action(function (Song $record, Column $column): void {
                    $this->calls[] = sprintf('closure:%s:%s', $record->title, $column->getRecordKey() ?? '');
                }),
                TextColumn::make('album')->action('appendAlbumCall'),
                TextColumn::make('actionObject')->state('view')->action(Action::make('view')->action(function (Song $record): void {
                    $this->calls[] = 'object:' . $record->title;
                })),
                TextColumn::make('id'),
                TextColumn::make('secret')->hidden()->action(function (): void {
                    $this->calls[] = 'hidden';
                }),
                TextColumn::make('toggled')->toggleable(isToggledHiddenByDefault: true)->action(function (Song $record): void {
                    $this->calls[] = 'toggled:' . $record->title;
                }),
            ])
            ->reorderableColumns();
    }

    public function appendAlbumCall(?object $record = null): string
    {
        $this->calls[] = 'string:' . ($record instanceof Song ? $record->title : 'none');

        return 'called';
    }
}
