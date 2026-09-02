<?php

declare(strict_types=1);

namespace Sylament\Tables\Tests\Integration;

use Sylament\Tables\Tests\Fixtures\Twig\Components\UnboundSongsTable;
use Sylament\Tables\Tests\Fixtures\Twig\Components\UrlBoundSongsTable;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

use function Sylament\Tables\Tests\browseTo;
use function Sylament\Tables\Tests\publishedProps;
use function Sylament\Tables\Tests\renderedRowsOf;

/*
 * What a table remembers between two visits in one session. Both fixture hosts
 * render on every request and only one of them opted into persisting sort,
 * search and filters — so most assertions below are a pair: the opted-in host
 * remembers, the other does not. Per-page is the exception with no opt-in at
 * all, which is why both hosts remember it.
 *
 * Precedence is the point: the query string wins over the session, the session
 * fills only what the query string left blank, and what neither names falls
 * back to the table's own defaults.
 */

function sessionKeyFor(string $hostClass, string $suffix): string
{
    return sprintf('sylament.tables.%s_%s', md5($hostClass), $suffix);
}

/**
 * Open the page once so a session exists, write the given state into it, and
 * leave its cookie in the jar — the next `browseTo()` reads exactly this back.
 *
 * @param array<string, mixed> $state
 */
function seedTableSession(array $state): KernelBrowser
{
    [$client] = browseTo();

    $session = $client->getRequest()->getSession();

    foreach ($state as $key => $value) {
        $session->set($key, $value);
    }

    $session->save();

    return $client;
}

/**
 * @return array<string, mixed>
 */
function currentTableSession(KernelBrowser $client): array
{
    return $client->getRequest()->getSession()->all();
}

it('remembers a sort the table asked it to persist', function (): void {
    $client = seedTableSession([sessionKeyFor(UrlBoundSongsTable::class, 'sort') => 'title:asc']);

    [, $crawler] = browseTo(client: $client);

    expect(publishedProps($crawler, '#bound-table')['tableSort'])->toBe('title:asc')
        ->and(publishedProps($crawler, '#unbound-table')['tableSort'])->toBeNull();
});

it('remembers nothing for a table that opted out', function (): void {
    $client = seedTableSession([sessionKeyFor(UnboundSongsTable::class, 'sort') => 'title:asc']);

    [, $crawler] = browseTo(client: $client);

    expect(publishedProps($crawler, '#unbound-table')['tableSort'])->toBeNull();
});

it('lets the query string beat the session, and remembers what the query string said', function (): void {
    $client = seedTableSession([sessionKeyFor(UrlBoundSongsTable::class, 'sort') => 'title:asc']);

    [, $crawler] = browseTo('?sort=title:desc', $client);

    expect(publishedProps($crawler, '#bound-table')['tableSort'])->toBe('title:desc')
        ->and(currentTableSession($client)[sessionKeyFor(UrlBoundSongsTable::class, 'sort')])->toBe('title:desc');

    [, $crawler] = browseTo(client: $client);

    expect(publishedProps($crawler, '#bound-table')['tableSort'])->toBe('title:desc');
});

it('falls back to the table default when neither the query string nor the session names a sort', function (): void {
    [, $crawler] = browseTo();

    expect(publishedProps($crawler, '#bound-table')['tableSort'])->toBeNull();
});

it('remembers a search term and a filter bag it was told to persist', function (): void {
    $client = seedTableSession([
        sessionKeyFor(UrlBoundSongsTable::class, 'search') => 'Karma',
        sessionKeyFor(UrlBoundSongsTable::class, 'filters') => ['plays' => ['value' => '200']],
    ]);

    [, $crawler] = browseTo(client: $client);

    $props = publishedProps($crawler, '#bound-table');

    expect($props['tableSearch'])->toBe('Karma')
        ->and($props['tableFilters'])->toBe(['plays' => ['value' => '200'], 'featured' => ['value' => null]])
        ->and(renderedRowsOf($crawler, 'UrlBoundSongsTable'))->toContain('Karma Police');
});

it('remembers neither search nor filters for a table that opted out', function (): void {
    $client = seedTableSession([
        sessionKeyFor(UnboundSongsTable::class, 'search') => 'Karma',
        sessionKeyFor(UnboundSongsTable::class, 'filters') => ['plays' => ['value' => '200']],
    ]);

    [, $crawler] = browseTo(client: $client);

    $props = publishedProps($crawler, '#unbound-table');

    expect($props['tableSearch'])->toBe('')
        ->and($props['tableFilters'])->toBe(['plays' => ['value' => '100'], 'featured' => ['value' => null]]);
});

it('writes nothing at all for a table that opted out', function (): void {
    [$client] = browseTo();

    $keys = array_keys(currentTableSession($client));

    expect($keys)->not->toContain(sessionKeyFor(UnboundSongsTable::class, 'sort'))
        ->and($keys)->not->toContain(sessionKeyFor(UnboundSongsTable::class, 'search'))
        ->and($keys)->not->toContain(sessionKeyFor(UnboundSongsTable::class, 'filters'))
        ->and($keys)->toContain(sessionKeyFor(UrlBoundSongsTable::class, 'sort'));
});

it('lets a seeded search beat the remembered one, and remembers what the query string said', function (): void {
    $client = seedTableSession([sessionKeyFor(UrlBoundSongsTable::class, 'search') => 'Karma']);

    [, $crawler] = browseTo('?search=Airbag', $client);

    expect(publishedProps($crawler, '#bound-table')['tableSearch'])->toBe('Airbag')
        ->and(currentTableSession($client)[sessionKeyFor(UrlBoundSongsTable::class, 'search')])->toBe('Airbag');

    [, $crawler] = browseTo(client: $client);

    expect(publishedProps($crawler, '#bound-table')['tableSearch'])->toBe('Airbag');
});

it('lets a seeded filter beat the remembered one', function (): void {
    $client = seedTableSession([sessionKeyFor(UrlBoundSongsTable::class, 'filters') => ['plays' => ['value' => '200']]]);

    [, $crawler] = browseTo('?filters[plays][value]=100', $client);

    expect(publishedProps($crawler, '#bound-table')['tableFilters'])->toBe(['plays' => ['value' => '100'], 'featured' => ['value' => null]]);
});

it('leaves a remembered filter bag that is not filter-name-keyed where it is', function (): void {
    $client = seedTableSession([sessionKeyFor(UrlBoundSongsTable::class, 'filters') => ['plays', 'featured']]);

    [, $crawler] = browseTo(client: $client);

    expect(publishedProps($crawler, '#bound-table')['tableFilters'])->toBe(['plays' => ['value' => '100'], 'featured' => ['value' => null]])
        ->and(renderedRowsOf($crawler, 'UrlBoundSongsTable'))->toContain('Airbag');
});

it('writes the sort back on the round trip that changes it', function (): void {
    [$client, $crawler] = browseTo();

    $root = $crawler->filter('#bound-table [data-live-props-value]');

    $client->request(
        'POST',
        ($root->attr('data-live-url-value') ?? '') . '/sortTable',
        parameters: ['data' => json_encode([
            'props' => json_decode($root->attr('data-live-props-value') ?? '', true, flags: JSON_THROW_ON_ERROR),
            'args' => ['column' => 'title', 'direction' => 'desc'],
        ], flags: JSON_THROW_ON_ERROR)],
        server: ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest'],
    );

    expect($client->getResponse()->getStatusCode())->toBe(200)
        ->and(currentTableSession($client)[sessionKeyFor(UrlBoundSongsTable::class, 'sort')])->toBe('title:desc');
});

it('remembers a per-page choice for every table, opted in or not', function (): void {
    $client = seedTableSession([
        sessionKeyFor(UrlBoundSongsTable::class, 'per_page') => '5',
        sessionKeyFor(UnboundSongsTable::class, 'per_page') => '5',
    ]);

    [, $crawler] = browseTo(client: $client);

    expect(publishedProps($crawler, '#bound-table')['tableRecordsPerPage'])->toBe('5')
        ->and(publishedProps($crawler, '#unbound-table')['tableRecordsPerPage'])->toBe('5');
});

it('forgets a per-page choice the table no longer offers', function (): void {
    $client = seedTableSession([sessionKeyFor(UrlBoundSongsTable::class, 'per_page') => '17']);

    [, $crawler] = browseTo(client: $client);

    expect(publishedProps($crawler, '#bound-table')['tableRecordsPerPage'])->toBe('2')
        ->and(currentTableSession($client))->not->toHaveKey(sessionKeyFor(UrlBoundSongsTable::class, 'per_page'));
});

it('touches no session at all when there is no request to carry one', function (): void {
    $host = $this::getContainer()->get(UrlBoundSongsTable::class);

    $host->mountTablePersistedState();

    expect($host->tableSort)->toBeNull()
        ->and($host->tableSearch)->toBe('')
        ->and($host->tableRecordsPerPage)->toBeNull();
});

it('touches no session on a request that carries none', function (): void {
    $this::getContainer()->get(RequestStack::class)->push(new Request());

    $host = $this::getContainer()->get(UrlBoundSongsTable::class);

    $host->mountTablePersistedState();

    expect($host->tableSort)->toBeNull()
        ->and($host->tableSearch)->toBe('')
        ->and($host->tableRecordsPerPage)->toBeNull();
});
