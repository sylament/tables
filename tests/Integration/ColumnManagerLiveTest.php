<?php

declare(strict_types=1);

namespace Sylament\Tables\Tests\Integration;

use Symfony\Component\DomCrawler\Crawler;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;
use Symfony\UX\LiveComponent\Test\TestLiveComponent;

uses(InteractsWithLiveComponents::class);

/*
 * The column manager end to end, over a real live-component request cycle: the
 * draft the controller writes, the apply that promotes it, the reset that
 * restores the definition, and the stored order a reorderable table honours.
 * The state machine itself is pinned in the Unit bucket; this file pins that the
 * two props, the two actions and the rendered header row agree once hydration is
 * involved.
 *
 * Both fixtures declare the same four columns in the same order — `title`
 * (never toggleable), `plays` (toggleable, on), `position` (toggleable, hidden
 * by default) and `isFeatured` (hidden outright) — so an index into either bag
 * names the same column in every case below.
 */

/**
 * @return list<string>
 */
function renderedHeaders(TestLiveComponent $component): array
{
    return $component->render()->crawler()->filter('thead .sy-ta-header-cell')->each(static fn (Crawler $cell): string => mb_trim($cell->text()));
}

it('applies the draft only on apply, then hides the column and resets nothing else', function (): void {
    $component = $this->createLiveComponent('ColumnManagerTable');
    $draft = $component->component()->tableDeferredColumns;
    $draft[1]['isToggled'] = false;

    $component->set('tableDeferredColumns', $draft);

    expect(renderedHeaders($component))->toBe(['Title', 'Plays']);

    $component->call('applyTableColumnManager');

    expect(renderedHeaders($component))->toBe(['Title'])
        ->and($component->component()->tableColumns[1]['isToggled'])->toBeFalse();
});

it('hides a column toggled hidden by default on the first render and restores it on reset', function (): void {
    $component = $this->createLiveComponent('ColumnManagerTable');

    expect(renderedHeaders($component))->toBe(['Title', 'Plays']);

    $state = $component->component()->tableColumns;
    $state[2]['isToggled'] = true;
    $component->call('applyTableColumnManager', ['state' => $state]);

    expect(renderedHeaders($component))->toBe(['Title', 'Plays', 'Position']);

    $component->call('resetTableColumnManager');

    expect(renderedHeaders($component))->toBe(['Title', 'Plays']);
});

it('renders the columns in the stored order when they are reorderable, a toggled-on default included', function (): void {
    $component = $this->createLiveComponent('LiveColumnManagerTable');
    $state = array_reverse($component->component()->tableColumns);
    $state[1]['isToggled'] = true;

    $component->call('applyTableColumnManager', ['state' => $state]);

    expect(renderedHeaders($component))->toBe(['Position', 'Plays', 'Title']);
});

it('applies nothing from a forged draft', function (): void {
    $component = $this->createLiveComponent('ColumnManagerTable')
        ->set('tableDeferredColumns', [['type' => 'column', 'name' => 'title', 'isToggled' => false, 'isToggledHiddenByDefault' => false]])
        ->call('applyTableColumnManager');

    expect(renderedHeaders($component))->toBe(['Title', 'Plays']);
});

it('keeps the stored order across a round trip that is not a manager action', function (): void {
    $component = $this->createLiveComponent('LiveColumnManagerTable');

    $state = array_reverse($component->component()->tableColumns);
    $state[1]['isToggled'] = true;

    $component->call('applyTableColumnManager', ['state' => $state]);

    expect(renderedHeaders($component))->toBe(['Position', 'Plays', 'Title']);

    $component->set('tableRecordsPerPage', 5);

    expect(renderedHeaders($component))->toBe(['Position', 'Plays', 'Title']);
});
