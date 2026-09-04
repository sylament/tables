<?php

declare(strict_types=1);

namespace Sylament\Tables\Tests\Integration;

use Symfony\Component\DomCrawler\Crawler;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;

use function Sylament\Tables\Tests\browseTo;
use function Sylament\Tables\Tests\publishedProps;

uses(InteractsWithLiveComponents::class);

/*
 * The per-column search round trip over a real live-component request cycle:
 * the seed the props publish, a nested write reaching a dotted column, the
 * untrimmed bag on the wire against the trimmed map the query lane reads, the
 * columns a term must not reach, and the chip that clears one column.
 *
 * Every term is written at the column's own model path, which is the model the
 * search row renders — `tableColumnSearches.artist.name` for a dotted column.
 * A whole-prop `set()` would replace the bag and drop the seed with it, which is
 * exactly the shape the seeding exists to prevent.
 */

it('narrows the rows by one column and resets the page', function (): void {
    $component = $this->createLiveComponent('ColumnSearchTable')->call('gotoTablePage', ['page' => 2]);

    $component->set('tableColumnSearches.title', 'Air');

    expect((string) $component->render())->toContain('Airbag')
        ->and((string) $component->render())->not->toContain('Paranoid Android')
        ->and($component->component()->tablePage)->toBe(1);
});

it('reaches a dotted column through its nested path', function (): void {
    $component = $this->createLiveComponent('ColumnSearchTable')->set('tableColumnSearches.artist.name', 'Portis');

    expect($component->component()->getTableColumnSearches()['artist.name'])->toBe('Portis')
        ->and((string) $component->render())->toContain('Glory Box')
        ->and((string) $component->render())->not->toContain('Airbag');
});

it('carries the nested, untrimmed bag on the wire', function (): void {
    $component = $this->createLiveComponent('ColumnSearchTable')->set('tableColumnSearches.artist.name', ' Radio ');

    $props = json_decode($component->render()->crawler()->filter('[data-live-props-value]')->attr('data-live-props-value') ?? '', true, flags: JSON_THROW_ON_ERROR);

    expect($props['tableColumnSearches']['artist'])->toBe(['name' => ' Radio '])
        ->and(array_keys($props['tableColumnSearches']))->toBe(['title', 'artist', 'entries', 'mood'])
        ->and((string) $component->render())->toContain('Airbag');
});

it('publishes a seeded key for every individually searchable column, entries included', function (): void {
    $searches = $this->createLiveComponent('ColumnSearchTable')->component()->tableColumnSearches;

    expect(array_keys($searches))->toBe(['title', 'artist', 'entries', 'mood'])
        ->and($searches['artist'])->toBe(['name' => ''])
        ->and($searches['title'])->toBe('')
        ->and($searches['entries'])->toBe('');
});

it('round-trips a column named after a reserved JavaScript array property', function (): void {
    $component = $this->createLiveComponent('ColumnSearchTable')->set('tableColumnSearches.entries', '33');

    expect($component->component()->tableColumnSearches['entries'])->toBe('33')
        ->and((string) $component->render())->toContain('Glory Box')
        ->and((string) $component->render())->not->toContain('Airbag');
});

it('ignores a term set on a hidden or a merely global column', function (): void {
    $component = $this->createLiveComponent('ColumnSearchTable')
        ->set('tableColumnSearches.mood', 'zzz')
        ->set('tableColumnSearches.plays', '999');

    expect((string) $component->render())->toContain('Airbag');
});

it('renders the chip between the search chip and the filter chips and clears it by column', function (): void {
    $component = $this->createLiveComponent('ColumnSearchTable')
        ->set('tableSearch', 'a')
        ->set('tableColumnSearches.title', 'Air');

    $chips = $component->render()->crawler()->filter('.sy-ta-filter-indicator')->each(static fn (Crawler $chip): string => mb_trim($chip->text()));

    expect($chips[0])->toStartWith('Search:')
        ->and($chips[1])->toBe('Title: Air');

    $component->call('resetTableColumnSearch', ['column' => 'title']);

    expect($component->component()->tableColumnSearches['title'])->toBe('')
        ->and((string) $component->render())->toContain('Paranoid Android');
});

it('publishes the seed on the first, non-live page render', function (): void {
    [, $crawler] = browseTo();

    expect(publishedProps($crawler, '#column-search-table')['tableColumnSearches'])->toBe(['title' => ''])
        ->and(publishedProps($crawler, '#bound-table')['tableColumnSearches'])->toBe([]);
});
