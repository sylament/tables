<?php

declare(strict_types=1);

namespace Sylament\Tables\Tests\Integration;

use Sylament\Tables\Tests\Fixtures\Doctrine\Entity\Song;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;

uses(InteractsWithLiveComponents::class);

/*
 * The grouping round trip over a real live-component request cycle: the write
 * action, the two refusals a forged value meets there, the page reset that
 * follows a re-order of the rows, and the group checkbox's keys — enumerated
 * across the whole filtered set, written back through the selection props the
 * record checkboxes already use, and re-filtered through the selectability
 * callback like any other client-written selection.
 *
 * The enumeration's own semantics — the page-only arm, an unknown group, an
 * ungrouped table and the per-request memo — are pinned method-side in
 * `tests/Unit/Concerns/HasBulkActionsTest.php` over the same SQLite rig; each
 * is re-asserted here at the wire, where the keys reach the client as
 * `data-sy-group-keys` and where the host is the hydrated one a request
 * actually holds.
 */

it('resets the page when a grouping is written', function (): void {
    $component = $this->createLiveComponent('GroupedSongsTable')
        ->call('gotoTablePage', ['page' => 2]);

    expect($component->component()->tablePage)->toBe(2);

    $component->call('groupTable', ['group' => 'artist.name', 'direction' => 'asc']);

    expect($component->component()->tablePage)->toBe(1)
        ->and($component->component()->tableGrouping)->toBe('artist.name');
});

it('groups the rendered rows by the grouping it was handed', function (): void {
    $component = $this->createLiveComponent('GroupedSongsTable');

    expect((string) $component->render())->not->toContain('sy-ta-group-header-row');

    $component->call('groupTable', ['group' => 'plays', 'direction' => 'desc']);

    $html = (string) $component->render();

    expect($component->component()->tableGrouping)->toBe('plays:desc')
        ->and($html)->toContain('sy-ta-group-header-row')
        ->and($html)->toContain('Plays: 300')
        ->and($html)->toContain('Somersault');
});

it('refuses a grouping naming a group the table does not offer', function (): void {
    $component = $this->createLiveComponent('GroupedSongsTable')
        ->call('groupTable', ['group' => 'mood', 'direction' => 'asc']);

    expect($component->component()->tableGrouping)->toBeNull()
        ->and($component->component()->getTableGrouping())->toBeNull()
        ->and((string) $component->render())->not->toContain('sy-ta-group-header-row');
});

it('refuses a tampered direction outright, keeping the grouping it had', function (): void {
    $component = $this->createLiveComponent('GroupedSongsTable')
        ->call('groupTable', ['group' => 'artist.name', 'direction' => 'asc'])
        ->call('groupTable', ['group' => 'artist.name', 'direction' => 'sideways']);

    expect($component->component()->tableGrouping)->toBe('artist.name')
        ->and($component->component()->getTableGroupingDirection())->toBe('asc');
});

it('hands the first page every key of a group that spans three of them', function (): void {
    $component = $this->createLiveComponent('GroupedSongsTable')
        ->call('groupTable', ['group' => 'artist.name', 'direction' => 'asc']);

    $html = (string) $component->render();

    expect($html)->toContain('data-sy-group-keys="[&quot;1&quot;,&quot;2&quot;,&quot;3&quot;]"')
        ->and($component->component()->getGroupedSelectableTableRecordKeys('Radiohead'))->toBe(['1', '2', '3']);
});

it('lands a whole group in the selection bag when the group checkbox writes', function (): void {
    $component = $this->createLiveComponent('GroupedSongsTable')
        ->call('groupTable', ['group' => 'artist.name', 'direction' => 'asc'])
        ->set('selectedTableRecords', ['1', '2', '3']);

    $host = $component->component();

    expect($host->isTableRecordSelected('1'))->toBeTrue()
        ->and($host->isTableRecordSelected('3'))->toBeTrue()
        ->and($host->isTableRecordSelected('4'))->toBeFalse()
        ->and(array_map(
            static fn (object $record): int => $record instanceof Song ? $record->getId() : 0,
            $host->getSelectedTableRecords(),
        ))->toBe([1, 2, 3]);
});

it('re-applies the selectability callback to a group the client wrote', function (): void {
    $component = $this->createLiveComponent('GroupedSongsTable', ['checksSelectable' => true])
        ->call('groupTable', ['group' => 'artist.name', 'direction' => 'asc']);

    expect($component->component()->getGroupedSelectableTableRecordKeys('Radiohead'))->toBe(['1', '3']);

    $host = $component->set('selectedTableRecords', ['1', '2', '3'])->component();

    expect(array_map(
        static fn (object $record): int => $record instanceof Song ? $record->getId() : 0,
        $host->getSelectedTableRecords(),
    ))->toBe([1, 3]);
});

it('enumerates only the rendered page for a group where selection is page-only', function (): void {
    $component = $this->createLiveComponent('GroupedSongsTable', ['selectsCurrentPageOnly' => true])
        ->call('groupTable', ['group' => 'artist.name', 'direction' => 'asc']);

    expect((string) $component->render())->toContain('data-sy-group-keys="[&quot;1&quot;,&quot;2&quot;]"')
        ->and($component->component()->getGroupedSelectableTableRecordKeys('Radiohead'))->toBe(['1', '2']);
});

it('enumerates nothing for a group key the table does not hold', function (): void {
    $component = $this->createLiveComponent('GroupedSongsTable')
        ->call('groupTable', ['group' => 'artist.name', 'direction' => 'asc']);

    expect($component->component()->getGroupedSelectableTableRecordKeys('Nobody'))->toBe([]);
});

it('enumerates nothing at all while no grouping is in force', function (): void {
    $host = $this->createLiveComponent('GroupedSongsTable')->component();

    expect($host->tableGrouping)->toBeNull()
        ->and($host->getGroupedSelectableTableRecordKeys('Radiohead'))->toBe([]);
});

it('memoises a group enumeration for the length of one request', function (): void {
    // `component()` hands back a freshly hydrated host, which is the per-request
    // instance the memo is scoped to.
    $host = $this->createLiveComponent('GroupedSongsTable')
        ->call('groupTable', ['group' => 'artist.name', 'direction' => 'asc'])
        ->component();

    $beforeFirstRead = $host->recordedStatementCount();

    expect($host->getGroupedSelectableTableRecordKeys('Radiohead'))->toBe(['1', '2', '3']);

    $afterFirstRead = $host->recordedStatementCount();

    $host->getGroupedSelectableTableRecordKeys('Radiohead');

    expect($afterFirstRead)->toBeGreaterThan($beforeFirstRead)
        ->and($host->recordedStatementCount())->toBe($afterFirstRead);

    // A second group is a second memo entry, so the counter moves again.
    $host->getGroupedSelectableTableRecordKeys('Zero 7');

    expect($host->recordedStatementCount())->toBeGreaterThan($afterFirstRead);
});
