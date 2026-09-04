<?php

declare(strict_types=1);

use Doctrine\ORM\EntityManager;
use Sylament\Tables\Columns\TextColumn;
use Sylament\Tables\Enums\PaginationMode;
use Sylament\Tables\Table;
use Sylament\Tables\Tests\Fixtures\Concerns\TableHost;
use Sylament\Tables\Tests\Fixtures\Doctrine\Entity\Artist;
use Sylament\Tables\Tests\Fixtures\Doctrine\Entity\Song;
use Sylament\Tables\Tests\Fixtures\Doctrine\EntityManagerFactory;
use Sylament\Tables\Tests\Fixtures\Doctrine\RecordingMiddleware;

/*
 * Covers the query half of the selection — the constraint `getSelectedTableRecordsQuery()`
 * composes and what the two resolution arms return through it. The records-lane
 * half and the live round trip stay in the Integration suite of the same name.
 */

function selectableSongs(): EntityManager
{
    $entityManager = EntityManagerFactory::create();

    $radiohead = new Artist(1, 'Radiohead');

    EntityManagerFactory::seed(
        $entityManager,
        $radiohead,
        new Song(1, 'Alpha', 10, $radiohead),
        new Song(2, 'Beta', 20, $radiohead),
        new Song(3, 'Gamma', 30),
    );

    return $entityManager;
}

/**
 * @param (Closure(Table): Table)|null $configure
 */
function selectionHost(EntityManager $entityManager, ?Closure $configure = null): TableHost
{
    return new TableHost(function (Table $table) use ($entityManager, $configure): Table {
        $table
            ->query($entityManager->createQueryBuilder()->select('song')->from(Song::class, 'song')->orderBy('song.id'))
            ->columns([TextColumn::make('title')->searchable()]);

        return $configure instanceof Closure ? $configure($table) : $table;
    });
}

it('constrains the query to the selected keys', function (): void {
    $host = selectionHost(selectableSongs());

    $host->selectedTableRecordKeys = ['1', '3'];

    expect(array_map(
        static fn (Song $song): string => $song->getTitle(),
        $host->getSelectedTableRecords(),
    ))->toBe(['Alpha', 'Gamma'])
        ->and($host->getSelectedTableRecordsQuery()->getDQL())
        ->toContain('song.id IN (:sylament_selected_record_keys)');
});

it('constrains the query away from the deselected keys while tracking', function (): void {
    $host = selectionHost(selectableSongs());
    $keyHost = selectionHost(selectableSongs());

    foreach ([$host, $keyHost] as $tracking) {
        $tracking->isTrackingDeselectedTableRecords = true;
        $tracking->deselectedTableRecords = ['2'];
    }

    expect(array_map(
        static fn (Song $song): string => $song->getTitle(),
        $host->getSelectedTableRecords(),
    ))->toBe(['Alpha', 'Gamma'])
        ->and($keyHost->getSelectedTableRecords(shouldFetchSelectedRecords: false))->toBe(['1', '3'])
        ->and($host->getSelectedTableRecordsQuery()->getDQL())
        ->toContain('song.id NOT IN (:sylament_selected_record_keys)');
});

/*
 * The default select-all path: the controller flips tracking on and empties both
 * bags, so the deselection bag is empty and the selection is *everything*. An
 * emitted `NOT IN ()` would invert to nothing at all — Doctrine expands an empty
 * list parameter to NULL — so the constraint has to be omitted instead.
 */
it('selects every filtered row while tracking an empty deselection bag', function (): void {
    $host = selectionHost(selectableSongs());
    $keyHost = selectionHost(selectableSongs());

    foreach ([$host, $keyHost] as $tracking) {
        $tracking->isTrackingDeselectedTableRecords = true;
        $tracking->deselectedTableRecords = [];
    }

    expect($host->hasSelectedTableRecords())->toBeTrue()
        ->and($host->getSelectedTableRecords())->toHaveCount(3)
        ->and($keyHost->getSelectedTableRecords(shouldFetchSelectedRecords: false))->toBe(['1', '2', '3'])
        ->and($host->getSelectedTableRecordsQuery()->getDQL())->not->toContain('NOT IN');
});

/*
 * The tracking flag is the one client value whose forgery *widens* the reach, so
 * it is re-derived against the same gate the checkboxes render from: a page-only
 * query table and a table that cannot track at all both resolve a forged flag as
 * the plain key selection rather than as every filtered row.
 */
it('refuses a tracking flag the table would never have offered', function (): void {
    $pageOnly = selectionHost(
        selectableSongs(),
        static fn (Table $table): Table => $table->selectCurrentPageOnly(),
    );
    $untracked = selectionHost(
        selectableSongs(),
        static fn (Table $table): Table => $table->deselectAllRecordsWhenFiltered(false),
    );

    foreach ([$pageOnly, $untracked] as $forged) {
        $forged->isTrackingDeselectedTableRecords = true;
        $forged->deselectedTableRecords = [];
        $forged->selectedTableRecordKeys = ['1'];
    }

    expect($pageOnly->getSelectedTableRecords(shouldFetchSelectedRecords: false))->toBe(['1'])
        ->and($pageOnly->isTableRecordSelected('2'))->toBeFalse()
        ->and($pageOnly->getSelectedTableRecordsQuery()->getDQL())
        ->toContain('song.id IN (:sylament_selected_record_keys)')
        ->and($untracked->getSelectedTableRecords(shouldFetchSelectedRecords: false))->toBe(['1'])
        ->and($untracked->isTableRecordSelected('2'))->toBeFalse()
        ->and($untracked->getSelectedTableRecordsQuery()->getDQL())
        ->toContain('song.id IN (:sylament_selected_record_keys)');
});

it('reports nothing selected for a forged tracking flag over an empty selection', function (): void {
    $host = selectionHost(
        selectableSongs(),
        static fn (Table $table): Table => $table->selectCurrentPageOnly(),
    );

    $host->isTrackingDeselectedTableRecords = true;

    expect($host->hasSelectedTableRecords())->toBeFalse()
        ->and($host->getSelectedTableRecords())->toBe([]);
});

it('plucks the keys instead of hydrating when told not to fetch', function (): void {
    $host = selectionHost(selectableSongs());

    $host->selectedTableRecordKeys = ['3', '1'];

    expect($host->getSelectedTableRecords(shouldFetchSelectedRecords: false))->toBe(['1', '3']);
});

it('constrains the non-fetching query to the selectable records', function (): void {
    $host = selectionHost(
        selectableSongs(),
        static fn (Table $table): Table => $table->checkIfRecordIsSelectableUsing(
            static fn (Song $record): bool => $record->getId() !== 2,
        ),
    );
    $queryHost = selectionHost(
        selectableSongs(),
        static fn (Table $table): Table => $table->checkIfRecordIsSelectableUsing(
            static fn (Song $record): bool => $record->getId() !== 2,
        ),
    );

    foreach ([$host, $queryHost] as $selecting) {
        $selecting->selectedTableRecordKeys = ['1', '2', '3'];
    }

    expect($host->getSelectedTableRecords(shouldFetchSelectedRecords: false))->toBe(['1', '3'])
        ->and($queryHost->getSelectedTableRecordsQuery(shouldFetchSelectedRecords: false)->getDQL())
        ->toContain('song.id IN (:sylament_selectable_record_keys)')
        ->and($queryHost->getSelectedTableRecordsQuery()->getDQL())
        ->not->toContain(':sylament_selectable_record_keys');
});

it('re-applies the filters to the selection only where the table deselects on filtering', function (): void {
    $deselecting = selectionHost(selectableSongs());
    $keeping = selectionHost(
        selectableSongs(),
        static fn (Table $table): Table => $table->deselectAllRecordsWhenFiltered(false),
    );

    foreach ([$deselecting, $keeping] as $host) {
        $host->selectedTableRecordKeys = ['1', '2'];
        $host->tableSearch = 'Alpha';
    }

    expect($deselecting->getSelectedTableRecords(shouldFetchSelectedRecords: false))->toBe(['1'])
        ->and($keeping->getSelectedTableRecords(shouldFetchSelectedRecords: false))->toBe(['1', '2'])
        ->and($deselecting->getSelectedTableRecordsQuery()->getDQL())->toContain('LIKE')
        ->and($keeping->getSelectedTableRecordsQuery()->getDQL())->not->toContain('LIKE');
});

it('eager-loads the columns relations only on the fetching arm', function (): void {
    $host = selectionHost(
        selectableSongs(),
        static fn (Table $table): Table => $table->columns([TextColumn::make('artist.name')]),
    );

    $host->selectedTableRecordKeys = ['1'];

    expect($host->getSelectedTableRecordsQuery()->getDQL())->toContain('JOIN song.artist')
        ->and($host->getSelectedTableRecordsQuery(shouldFetchSelectedRecords: false)->getDQL())
        ->not->toContain('JOIN');
});

it('enumerates every filtered key as the selectable set, callback or not', function (): void {
    $host = selectionHost(selectableSongs());
    $selectable = selectionHost(
        selectableSongs(),
        static fn (Table $table): Table => $table->checkIfRecordIsSelectableUsing(
            static fn (Song $record): bool => $record->getId() !== 2,
        ),
    );

    expect($host->getAllSelectableTableRecordKeys())->toBe(['1', '2', '3'])
        ->and($selectable->getAllSelectableTableRecordKeys())->toBe(['1', '3']);
});

it('counts the paginator total as the selection ceiling', function (): void {
    $host = selectionHost(selectableSongs(), static fn (Table $table): Table => $table->paginated([2]));
    $selectable = selectionHost(
        selectableSongs(),
        static fn (Table $table): Table => $table
            ->paginated([2])
            ->checkIfRecordIsSelectableUsing(static fn (Song $record): bool => $record->getId() !== 2),
    );

    expect($host->getAllSelectableTableRecordsCount())->toBe(3)
        ->and($selectable->getAllSelectableTableRecordsCount())->toBe(2);
});

/*
 * Simple pagination is the one query-lane shape whose paginator carries no total,
 * and the ceiling is Filament's last arm rather than a null: a count over the
 * filtered query.
 */
it('counts the filtered query where simple pagination carries no total', function (): void {
    $host = selectionHost(
        selectableSongs(),
        static fn (Table $table): Table => $table->paginated([2])->paginationMode(PaginationMode::Simple),
    );

    expect($host->getTableRecords()->getTotalRecordCount())->toBeNull()
        ->and($host->getAllSelectableTableRecordsCount())->toBe(3);
});

/*
 * The grouped enumeration behind a group header's checkbox: the keys of one
 * group, taken from the whole filtered set or from the page alone, and keyed
 * through `Group::getStringKey()` on both lanes — the reader the header rendered
 * its own key with, so the two cannot disagree.
 */

/**
 * @param (Closure(Table): Table)|null $configure
 */
function groupedSelectionHost(EntityManager $entityManager, ?Closure $configure = null): TableHost
{
    return selectionHost($entityManager, function (Table $table) use ($configure): Table {
        $table->groups(['plays', 'artist.name'])->paginated([2]);

        return $configure instanceof Closure ? $configure($table) : $table;
    });
}

it('enumerates every key of one group across the pages', function (): void {
    $host = groupedSelectionHost(selectableSongs());
    $host->tableGrouping = 'artist.name';

    expect($host->getGroupedSelectableTableRecordKeys('Radiohead'))->toBe(['1', '2'])
        ->and($host->getGroupedSelectableTableRecordKeys(null))->toBe(['3']);
});

it('enumerates only the page of a group where selection is page-only', function (): void {
    $host = groupedSelectionHost(
        selectableSongs(),
        fn (Table $table): Table => $table->paginated([1])->selectCurrentPageOnly(),
    );
    $host->tableGrouping = 'artist.name';

    // Radiohead holds two songs across the filtered set and one on this page,
    // and the group whose key is blank holds none of it.
    expect(array_keys($host->getTable()->getRecords()))->toBe([1])
        ->and($host->getGroupedSelectableTableRecordKeys('Radiohead'))->toBe(['1'])
        ->and($host->getGroupedSelectableTableRecordKeys(null))->toBe([]);
});

it('re-applies the selectability callback to a grouped enumeration on both lanes', function (): void {
    $configure = static fn (Table $table): Table => $table->checkIfRecordIsSelectableUsing(
        static fn (Song $record): bool => $record->getId() !== 2,
    );

    $wholeSet = groupedSelectionHost(selectableSongs(), $configure);
    $wholeSet->tableGrouping = 'artist.name';

    $pageOnly = groupedSelectionHost(
        selectableSongs(),
        static fn (Table $table): Table => $configure($table)->selectCurrentPageOnly(),
    );
    $pageOnly->tableGrouping = 'plays';

    expect($wholeSet->getGroupedSelectableTableRecordKeys('Radiohead'))->toBe(['1'])
        ->and($pageOnly->getGroupedSelectableTableRecordKeys('20'))->toBe([]);
});

it('enumerates nothing for a group the table does not offer, or for none at all', function (): void {
    $grouped = groupedSelectionHost(selectableSongs());
    $grouped->tableGrouping = 'artist.name';

    $ungrouped = groupedSelectionHost(selectableSongs());

    expect($grouped->getGroupedSelectableTableRecordKeys('Nobody'))->toBe([])
        ->and($ungrouped->getGroupedSelectableTableRecordKeys('Radiohead'))->toBe([]);
});

it('memoises a grouped enumeration per group for the request', function (): void {
    $recorder = new RecordingMiddleware();
    $entityManager = EntityManagerFactory::create($recorder);
    $radiohead = new Artist(1, 'Radiohead');

    EntityManagerFactory::seed(
        $entityManager,
        $radiohead,
        new Song(1, 'Alpha', 10, $radiohead),
        new Song(2, 'Beta', 20, $radiohead),
    );

    $host = groupedSelectionHost($entityManager);
    $host->tableGrouping = 'artist.name';

    $recorder->reset();

    $host->getGroupedSelectableTableRecordKeys('Radiohead');
    $host->getGroupedSelectableTableRecordKeys('Radiohead');

    expect($recorder->statements())->toHaveCount(1)
        ->and($host->getGroupedSelectableTableRecordKeys('Radiohead'))->toBe(['1', '2']);
});

it('forgets a grouped enumeration wherever the selection memo is dropped', function (string $method): void {
    $recorder = new RecordingMiddleware();
    $entityManager = EntityManagerFactory::create($recorder);
    $radiohead = new Artist(1, 'Radiohead');

    EntityManagerFactory::seed($entityManager, $radiohead, new Song(1, 'Alpha', 10, $radiohead));

    $host = groupedSelectionHost($entityManager);
    $host->tableGrouping = 'artist.name';

    $host->getGroupedSelectableTableRecordKeys('Radiohead');

    $host->{$method}();

    // The clearer runs queries of its own, so the count is taken after it and
    // the assertion is that the *next* read adds to it rather than answering
    // from a memo the clearer should have dropped.
    $afterClearing = count($recorder->statements());

    $host->getGroupedSelectableTableRecordKeys('Radiohead');

    expect(count($recorder->statements()))->toBeGreaterThan($afterClearing)
        ->and($host->getGroupedSelectableTableRecordKeys('Radiohead'))->toBe(['1']);
})->with(['flushCachedTableRecords', 'deselectAllTableRecords', 'selectAllTableRecords']);
