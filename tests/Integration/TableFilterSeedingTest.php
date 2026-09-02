<?php

declare(strict_types=1);

namespace Sylament\Tables\Tests\Integration;

use function Sylament\Tables\Tests\browseTo;
use function Sylament\Tables\Tests\publishedProps;
use function Sylament\Tables\Tests\renderedRowsOf;

/*
 * The read side of a seeded filter bag, which the mount hook used to destroy:
 * it filled the form with the components' own defaults and — under the deferred
 * default — copied that draft over whatever the URL had just put in the applied
 * bag. The bag now fills the form instead, and the copy runs after it.
 *
 * The table under test carries a select filter defaulting to 100 plays and a
 * ternary over `isFeatured`, so both halves have something to prove: a seeded
 * select must beat its own default, and a seeded ternary must arrive as a
 * boolean rather than as the truthy string `'false'`.
 */

it('applies a seeded filter over the default the filter declares', function (): void {
    [, $crawler] = browseTo('?filters[plays][value]=200');

    $rows = renderedRowsOf($crawler, 'UrlBoundSongsTable');

    expect($rows)->toContain('Paranoid Android')
        ->and($rows)->toContain('Karma Police')
        ->and($rows)->not->toContain('Airbag');
});

it('shows the seeded value in the filter form, not the default', function (): void {
    [, $crawler] = browseTo('?filters[plays][value]=200');

    $props = publishedProps($crawler, '#bound-table');

    expect($props['tableFilters'])->toBe(['plays' => ['value' => '200'], 'featured' => ['value' => null]])
        ->and($props['tableDeferredFilters'])->toBe(['plays' => ['value' => '200'], 'featured' => ['value' => null]]);

    $selected = $crawler->filter('#bound-table select[id$="plays.value"] option[selected]');

    expect($selected->attr('value'))->toBe('200');
});

it("normalizes the query string's stringly-typed booleans before a filter reads them", function (): void {
    [, $crawler] = browseTo('?filters[featured][value]=false');

    $rows = renderedRowsOf($crawler, 'UrlBoundSongsTable');

    $filters = publishedProps($crawler, '#bound-table')['tableFilters'];

    expect($filters)->toBeArray();
    assert(is_array($filters));

    expect($filters['featured']['value'])->not->toBeString()
        ->and($rows)->toContain('Paranoid Android')
        ->and($rows)->not->toContain('Airbag')
        ->and($rows)->not->toContain('Exit Music');
});

it('normalizes a seeded null the same way, leaving the unseeded filter empty rather than defaulted', function (): void {
    [, $crawler] = browseTo('?filters[featured][value]=null');

    expect(publishedProps($crawler, '#bound-table')['tableFilters'])->toBe([
        'featured' => ['value' => null],
        'plays' => ['value' => null],
    ]);
});

it('clears a seeded bag that is not filter-name-keyed, rather than leaving it where UX put it', function (): void {
    [$client, $crawler] = browseTo('?filters[]=abc');

    // UX assigns the bag before the seeding hook runs, so declining it has to
    // mean emptying it — a bag left holding a list suppresses every declared
    // default and keeps an integer key in an `array<string, mixed>` prop.
    expect($client->getResponse()->getStatusCode())->toBe(200)
        ->and(publishedProps($crawler, '#bound-table')['tableFilters'])->toBe(['plays' => ['value' => '100'], 'featured' => ['value' => null]])
        ->and(renderedRowsOf($crawler, 'UrlBoundSongsTable'))->toContain('Airbag');
});

it('falls back to every filter default when the query string names none', function (): void {
    [, $crawler] = browseTo();

    $rows = renderedRowsOf($crawler, 'UrlBoundSongsTable');

    expect(publishedProps($crawler, '#bound-table')['tableFilters'])->toBe(['plays' => ['value' => '100'], 'featured' => ['value' => null]])
        ->and($rows)->toContain('Airbag')
        ->and($rows)->not->toContain('Paranoid Android');
});
