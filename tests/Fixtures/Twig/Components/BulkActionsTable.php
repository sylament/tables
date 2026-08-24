<?php

declare(strict_types=1);

namespace Sylament\Tables\Tests\Fixtures\Twig\Components;

use Sylament\Actions\BulkAction;
use Sylament\Actions\Concerns\HasActions;
use Sylament\Actions\Contracts\InteractsWithActions;
use Sylament\Support\Pagination\Paginator;
use Sylament\Tables\Columns\TextColumn;
use Sylament\Tables\Concerns\HasTable;
use Sylament\Tables\Contracts\InteractsWithTable;
use Sylament\Tables\Table;
use Sylament\Tables\Tests\Fixtures\Records\Song;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * The selection host: a records-lane table carrying one bulk action, paginated
 * two to a page so a selection outlives a page change. The two flags are
 * `LiveProp`s so a test can rebuild the same table with the selectability
 * callback set, or with the filter deselect turned off, across a round trip.
 */
#[AsLiveComponent(name: 'BulkActionsTable', template: '@TablesFixtures/components/BulkActionsTable.html.twig')]
class BulkActionsTable implements InteractsWithActions, InteractsWithTable
{
    use DefaultActionTrait;
    use HasActions;
    use HasTable;

    #[LiveProp]
    public bool $checksSelectable = false;

    #[LiveProp]
    public bool $deselectsWhenFiltered = true;

    public function table(Table $table): Table
    {
        $table
            ->records(function (int $page, int|string|null $recordsPerPage, ?string $search): Paginator {
                $songs = array_values(array_filter(
                    $this->songs(),
                    static fn (Song $song): bool => $search === null
                        || str_contains(mb_strtolower($song->title), mb_strtolower($search)),
                ));

                assert(is_int($recordsPerPage));

                return new Paginator(
                    records: array_slice($songs, ($page - 1) * $recordsPerPage, $recordsPerPage),
                    totalRecordCount: count($songs),
                    page: $page,
                    recordsPerPage: $recordsPerPage,
                );
            })
            ->columns([TextColumn::make('title')->searchable()->sortable()])
            ->toolbarActions([BulkAction::make('archive')->action(fn (): null => null)])
            ->deselectAllRecordsWhenFiltered($this->deselectsWhenFiltered)
            ->paginated([2]);

        if ($this->checksSelectable) {
            $table->checkIfRecordIsSelectableUsing(fn (Song $record): bool => $record->id !== 3);
        }

        return $table;
    }

    /**
     * @return list<Song>
     */
    private function songs(): array
    {
        return [
            new Song('Airbag', 'OK Computer', id: 1),
            new Song('Paranoid Android', 'OK Computer', id: 2),
            new Song('Exit Music', 'OK Computer', id: 3),
            new Song('Let Down', 'OK Computer', id: 4),
            new Song('Karma Police', 'OK Computer', id: 5),
        ];
    }
}
