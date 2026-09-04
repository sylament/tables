<?php

declare(strict_types=1);

namespace Sylament\Tables\Tests\Integration\Concerns;

use Sylament\Tables\Columns\TextColumn;
use Sylament\Tables\Grouping\Group;
use Sylament\Tables\Table;
use Sylament\Tables\Tests\Fixtures\Concerns\TableHost;
use Sylament\Tables\Tests\Fixtures\Records\Song;
use Sylament\Tables\Tests\Fixtures\Records\Status;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;

uses(InteractsWithLiveComponents::class);

/*
 * Selection over a real live-component round trip. Every write below names the
 * **frontend** model `selectedTableRecords`, not the renamed property behind it
 * — the hydrator keys updated props by `fieldName:`, which is the whole point of
 * the rename, so the client vocabulary is what these tests speak.
 *
 * The three bags are untrusted client data: nothing in them proves a key names a
 * visible, selectable or extant record, which is why the assertions below cover
 * a tampered bag as well as a well-formed one.
 */

it('reads a selection out of the client bag, by key', function (): void {
    $host = $this->createLiveComponent('BulkActionsTable')
        ->set('selectedTableRecords', ['2', '3'])
        ->component();

    expect($host->isTableRecordSelected('2'))->toBeTrue()
        ->and($host->isTableRecordSelected('3'))->toBeTrue()
        ->and($host->isTableRecordSelected('1'))->toBeFalse()
        ->and($host->hasSelectedTableRecords())->toBeTrue();
});

it('inverts the answer while tracking deselections', function (): void {
    $host = $this->createLiveComponent('BulkActionsTable')
        ->set('isTrackingDeselectedTableRecords', true)
        ->set('deselectedTableRecords', ['2'])
        ->component();

    expect($host->isTableRecordSelected('2'))->toBeFalse()
        ->and($host->isTableRecordSelected('1'))->toBeTrue()
        ->and($host->isTableRecordSelected('99'))->toBeTrue()
        ->and($host->hasSelectedTableRecords())->toBeTrue();
});

it('reports nothing selected for an empty bag, tracking off', function (): void {
    $host = $this->createLiveComponent('BulkActionsTable')->component();

    expect($host->hasSelectedTableRecords())->toBeFalse()
        ->and($host->getSelectedTableRecords())->toBe([]);
});

it('normalizes a tampered bag away instead of trusting it', function (): void {
    $host = $this->createLiveComponent('BulkActionsTable')
        ->set('selectedTableRecords', ['2', ['nested'], true, false, 4])
        ->component();

    expect($host->isTableRecordSelected('2'))->toBeTrue()
        ->and($host->isTableRecordSelected('4'))->toBeTrue()
        ->and($host->isTableRecordSelected('1'))->toBeFalse()
        ->and($host->hasSelectedTableRecords())->toBeTrue();
});

it('resolves the selected records off the page collection on the records lane', function (): void {
    $host = $this->createLiveComponent('BulkActionsTable')
        ->set('selectedTableRecords', ['1', '2', '5'])
        ->component();

    expect(array_column($host->getSelectedTableRecords(), 'title'))->toBe(['Airbag', 'Paranoid Android']);
});

it('resolves the page minus the deselected records while tracking', function (): void {
    $host = $this->createLiveComponent('BulkActionsTable')
        ->set('isTrackingDeselectedTableRecords', true)
        ->set('deselectedTableRecords', ['1'])
        ->component();

    expect(array_column($host->getSelectedTableRecords(), 'title'))->toBe(['Paranoid Android']);
});

it('re-applies the selectability callback to whatever the client selected', function (): void {
    $host = $this->createLiveComponent('BulkActionsTable', ['checksSelectable' => true])
        ->call('gotoTablePage', ['page' => 2])
        ->set('selectedTableRecords', ['3', '4'])
        ->component();

    expect(array_column($host->getSelectedTableRecords(), 'title'))->toBe(['Let Down'])
        ->and($host->getAllSelectableTableRecordKeys())->toBe(['4']);
});

it('enumerates the page keys into the prop on selectAllTableRecords', function (): void {
    $component = $this->createLiveComponent('BulkActionsTable')->call('selectAllTableRecords');

    expect($component->component()->selectedTableRecordKeys)->toBe(['1', '2'])
        ->and($component->component()->isTrackingDeselectedTableRecords)->toBeFalse();

    $component->call('gotoTablePage', ['page' => 2])->call('selectAllTableRecords');

    expect($component->component()->selectedTableRecordKeys)->toBe(['3', '4']);
});

it('counts the page as the selectable ceiling on a records() table', function (): void {
    $host = $this->createLiveComponent('BulkActionsTable')->component();

    expect($host->getAllSelectableTableRecordsCount())->toBe(2);

    $selectable = $this->createLiveComponent('BulkActionsTable', ['checksSelectable' => true])
        ->call('gotoTablePage', ['page' => 2])
        ->component();

    expect($selectable->getAllSelectableTableRecordsCount())->toBe(1);
});

it('clears the selection when the filters change, and leaves it when told not to', function (): void {
    $deselecting = $this->createLiveComponent('BulkActionsTable')
        ->set('selectedTableRecords', ['1', '2'])
        ->set('tableFilters', ['plays' => ['value' => '200']]);

    expect($deselecting->component()->selectedTableRecordKeys)->toBe([]);

    $keeping = $this->createLiveComponent('BulkActionsTable', ['deselectsWhenFiltered' => false])
        ->set('selectedTableRecords', ['1', '2'])
        ->set('tableFilters', ['plays' => ['value' => '200']]);

    expect($keeping->component()->selectedTableRecordKeys)->toBe(['1', '2']);
});

it('clears the selection when the search term changes', function (): void {
    $component = $this->createLiveComponent('BulkActionsTable')
        ->set('isTrackingDeselectedTableRecords', true)
        ->set('deselectedTableRecords', ['1'])
        ->set('tableSearch', 'karma');

    $host = $component->component();

    expect($host->isTrackingDeselectedTableRecords)->toBeFalse()
        ->and($host->deselectedTableRecords)->toBe([])
        ->and($host->hasSelectedTableRecords())->toBeFalse();
});

it('clears the selection when the search is reset from its own action', function (): void {
    $component = $this->createLiveComponent('BulkActionsTable')
        ->set('tableSearch', 'karma')
        ->set('selectedTableRecords', ['5'])
        ->call('resetTableSearch');

    expect($component->component()->selectedTableRecordKeys)->toBe([]);
});

it('keeps the selection across a sort and a page change', function (): void {
    $component = $this->createLiveComponent('BulkActionsTable')
        ->set('selectedTableRecords', ['1', '2'])
        ->call('sortTable', ['column' => 'title', 'direction' => 'desc'])
        ->call('gotoTablePage', ['page' => 2]);

    expect($component->component()->selectedTableRecordKeys)->toBe(['1', '2']);
});

it('dehydrates the selection as the raw key list its checkboxes speak', function (): void {
    $html = (string) $this->createLiveComponent('BulkActionsTable')
        ->set('selectedTableRecords', ['1', '2'])
        ->render();

    preg_match('/data-live-props-value="([^"]*)"/', $html, $matches);

    $props = json_decode(html_entity_decode($matches[1] ?? ''), true, flags: JSON_THROW_ON_ERROR);

    expect($props)->toBeArray()
        ->and($props['selectedTableRecords'])->toBe(['1', '2'])
        ->and($props['deselectedTableRecords'])->toBe([])
        ->and($props['isTrackingDeselectedTableRecords'])->toBeFalse()
        ->and($props)->not->toHaveKey('selectedTableRecordKeys');
});

/*
 * The records lane of the grouped enumeration. Filament reads the group column
 * raw here — `$record[$groupColumn]`, keyed `array $record` — so a backed enum
 * is a TypeError, an object row is a TypeError, and `date()` and
 * `getKeyFromRecordUsing()` are both lost; the port keys this lane through
 * `Group::getStringKey()` exactly as it keys the query one.
 */

it('keys a records-lane group through getStringKey, enums and closures included', function (): void {
    $configure = fn (Table $table): Table => $table
        ->records(fn (): array => [
            new Song('Airbag', 'OK Computer', id: 1, status: Status::Published),
            new Song('Exit Music', 'OK Computer', id: 2, status: Status::Draft),
            new Song('Let Down', 'OK Computer', id: 3, status: Status::Published),
            new Song('No Surprises', 'OK Computer', id: 4),
        ])
        ->columns([TextColumn::make('title')])
        ->groups(['status', Group::make('initial')->getKeyFromRecordUsing(
            fn (Song $record): string => mb_substr($record->title, 0, 1),
        )]);

    $enumKeyed = new TableHost($configure);
    $enumKeyed->tableGrouping = 'status';

    $closureKeyed = new TableHost($configure);
    $closureKeyed->tableGrouping = 'initial';

    expect($enumKeyed->getGroupedSelectableTableRecordKeys('published'))->toBe(['1', '3'])
        ->and($enumKeyed->getGroupedSelectableTableRecordKeys('draft'))->toBe(['2'])
        ->and($enumKeyed->getGroupedSelectableTableRecordKeys(null))->toBe(['4'])
        ->and($closureKeyed->getGroupedSelectableTableRecordKeys('A'))->toBe(['1']);
});

it('re-applies the selectability callback to a records-lane group', function (): void {
    $host = new TableHost(fn (Table $table): Table => $table
        ->records(fn (): array => [
            new Song('Airbag', 'OK Computer', id: 1, status: Status::Published),
            new Song('Let Down', 'OK Computer', id: 3, status: Status::Published),
        ])
        ->columns([TextColumn::make('title')])
        ->groups(['status'])
        ->checkIfRecordIsSelectableUsing(fn (Song $record): bool => $record->id !== 3));

    $host->tableGrouping = 'status';

    expect($host->getGroupedSelectableTableRecordKeys('published'))->toBe(['1']);
});
