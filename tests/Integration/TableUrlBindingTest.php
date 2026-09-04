<?php

declare(strict_types=1);

namespace Sylament\Tables\Tests\Integration;

use function Sylament\Tables\Tests\browseTo;
use function Sylament\Tables\Tests\publishedProps;
use function Sylament\Tables\Tests\renderedRowsOf;

/*
 * The query-string half of the browsing state, driven the only way it can be:
 * through a real GET of a real page. UX seeds url-bound props from the *main*
 * request at PostMount, so a component mounted outside an HTTP request — which
 * is what `TestLiveComponent` does — is never seeded at all, and neither the
 * seeding nor the session hook would be exercised by one.
 *
 * The fixture page renders two hosts over the same six-song table: one binding
 * every state prop, one binding none. Both read the same query string; only the
 * first may act on it. The table's select filter carries a real default, so a
 * seeded filter has something to beat.
 */

it('seeds page, sort, search and filters from the query string of the page it is on', function (): void {
    [, $crawler] = browseTo('?page=2&sort=title:desc&search=a&filters[plays][value]=200');

    $props = publishedProps($crawler, '#bound-table');

    expect($props)->toMatchArray([
        'tablePage' => 2,
        'tableSort' => 'title:desc',
        'tableSearch' => 'a',
    ])->and($props['tableFilters'])->toBe(['plays' => ['value' => '200'], 'featured' => ['value' => null]]);
});

it('renders the page and order the query string asked for', function (): void {
    [, $crawler] = browseTo('?page=2&sort=title:asc');

    $rows = renderedRowsOf($crawler, 'UrlBoundSongsTable');

    expect($rows)->toContain('Let Down')
        ->and($rows)->toContain('No Surprises')
        ->and($rows)->not->toContain('Airbag');
});

it('floors a blank page rather than paginating from zero', function (): void {
    [, $crawler] = browseTo('?page=&sort=title:asc');

    expect(publishedProps($crawler, '#bound-table')['tablePage'])->toBe(1)
        ->and(renderedRowsOf($crawler, 'UrlBoundSongsTable'))->toContain('Airbag');
});

it('caps a page no offset can be computed from rather than overflowing one', function (): void {
    [$client, $crawler] = browseTo('?page=99999999999999999999');

    // The prop dehydrates through `getTablePage()`, so the cap is what reaches
    // the wire as well as what the offset is computed from.
    expect($client->getResponse()->getStatusCode())->toBe(200)
        ->and(publishedProps($crawler, '#bound-table')['tablePage'])->toBe(intdiv(PHP_INT_MAX, 2));
});

it('leaves the seeded page alone, because a seeded search fires no update hook', function (): void {
    [, $crawler] = browseTo('?page=2&search=o');

    expect(publishedProps($crawler, '#bound-table'))->toMatchArray([
        'tablePage' => 2,
        'tableSearch' => 'o',
    ]);
});

it('seeds nothing into a host that binds nothing, from the very same URL', function (): void {
    [, $crawler] = browseTo('?page=2&sort=title:desc&search=a&filters[plays][value]=200');

    $props = publishedProps($crawler, '#unbound-table');

    expect($props)->toMatchArray([
        'tablePage' => 1,
        'tableSort' => null,
        'tableSearch' => '',
    ])->and($props['tableFilters'])->toBe(['plays' => ['value' => '100'], 'featured' => ['value' => null]])
        ->and(renderedRowsOf($crawler, 'UnboundSongsTable'))->toContain('Airbag');
});

it('drops a seeded sort naming a column the table never declared sortable', function (): void {
    [, $crawler] = browseTo('?sort=isFeatured:desc');

    $rows = renderedRowsOf($crawler, 'UrlBoundSongsTable');

    // Cleared at mount rather than only at apply time, so the raw client string
    // reaches neither the ordering nor the `sortColumn` a closure is handed —
    // both of which read it back off this property.
    expect(publishedProps($crawler, '#bound-table')['tableSort'])->toBeNull()
        ->and($rows)->toContain('Airbag')
        ->and($rows)->toContain('Exit Music');
});

it('drops a seeded sort naming a real column with a direction that is not one', function (): void {
    [, $crawler] = browseTo('?sort=title:sideways');

    expect(publishedProps($crawler, '#bound-table')['tableSort'])->toBeNull();
});

it('keeps a seeded sort the table does declare sortable', function (): void {
    [, $crawler] = browseTo('?sort=title');

    expect(publishedProps($crawler, '#bound-table')['tableSort'])->toBe('title');
});

it('rebuilds the URL with the mapped names after a live round trip', function (): void {
    [$client, $crawler] = browseTo('?sort=title:desc');

    $root = $crawler->filter('#bound-table [data-live-props-value]');

    $client->request(
        'POST',
        $root->attr('data-live-url-value') ?? '',
        parameters: ['data' => json_encode(['props' => json_decode($root->attr('data-live-props-value') ?? '', true, flags: JSON_THROW_ON_ERROR)], flags: JSON_THROW_ON_ERROR)],
        server: [
            'HTTP_X_LIVE_URL' => '/fixtures/browsing-state?sort=title%3Adesc',
            'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
        ],
    );

    $rebuilt = $client->getResponse()->headers->get('X-Live-Url') ?? '';

    expect($rebuilt)->toStartWith('/fixtures/browsing-state?')
        ->and($rebuilt)->toContain('sort=title%3Adesc')
        ->and($rebuilt)->toContain('page=1')
        ->and($rebuilt)->toContain('filters%5Bplays%5D%5Bvalue%5D=100')
        ->and($rebuilt)->not->toContain('tableSort');
});

it('seeds a per-column term into the only host that maps it', function (): void {
    [, $crawler] = browseTo('?columnSearches[title]=Air');

    expect(publishedProps($crawler, '#column-search-table')['tableColumnSearches'])->toBe(['title' => 'Air'])
        ->and(publishedProps($crawler, '#bound-table')['tableColumnSearches'])->toBe([])
        ->and(renderedRowsOf($crawler, 'ColumnSearchUrlTable'))->toContain('Airbag')
        ->and(renderedRowsOf($crawler, 'ColumnSearchUrlTable'))->not->toContain('Let Down');
});

it('seeds a grouping into the only host that maps it', function (): void {
    [, $crawler] = browseTo('?grouping=plays');

    expect(publishedProps($crawler, '#bound-table')['tableGrouping'])->toBe('plays')
        ->and(publishedProps($crawler, '#unbound-table')['tableGrouping'])->toBeNull()
        // The seeded value reaches the rendered rows, not just the published
        // props: the bound host opens a group heading its unbound twin has not.
        ->and(renderedRowsOf($crawler, 'UrlBoundSongsTable'))->toContain('Plays: 100')
        ->and(renderedRowsOf($crawler, 'UnboundSongsTable'))->not->toContain('Plays: 100');
});

it('drops a seeded grouping naming a group the table does not offer', function (): void {
    [, $crawler] = browseTo('?grouping=isFeatured');

    // Cleared at mount rather than only at read time, so the raw client string
    // never reaches the ordering it would otherwise be re-checked against on
    // every read.
    expect(publishedProps($crawler, '#bound-table')['tableGrouping'])->toBeNull();
});

it('rebuilds the URL with the grouping after a live round trip', function (): void {
    [$client, $crawler] = browseTo('?grouping=plays');

    $root = $crawler->filter('#bound-table [data-live-props-value]');

    $client->request(
        'POST',
        $root->attr('data-live-url-value') ?? '',
        parameters: ['data' => json_encode(['props' => json_decode($root->attr('data-live-props-value') ?? '', true, flags: JSON_THROW_ON_ERROR)], flags: JSON_THROW_ON_ERROR)],
        server: [
            // Deliberately without the parameter the props carry, so the
            // rebuilt URL can only have gained it from the bound prop rather
            // than by echoing what was sent.
            'HTTP_X_LIVE_URL' => '/fixtures/browsing-state',
            'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
        ],
    );

    expect($client->getResponse()->headers->get('X-Live-Url') ?? '')->toContain('grouping=plays');
});
