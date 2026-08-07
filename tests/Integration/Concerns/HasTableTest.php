<?php

declare(strict_types=1);

namespace Sylament\Tables\Tests\Integration\Concerns;

use Symfony\Component\DomCrawler\Crawler;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;
use Twig\Environment;

uses(InteractsWithLiveComponents::class);

/*
 * Pins what the dehydration pass puts on the wire, both faces of it. The id
 * stamped on the rows is the id the next request hydrates back, and the values
 * bound to the per-page select and the search input are the values those
 * elements hold. Both only hold because Symfony's `PropertyAccessor` reads a
 * prop through its same-named getter, which mints the host id and would
 * otherwise poison the two writable props — see the divergences in
 * `docs/progress/tables/has-table.md`. Renaming either half breaks this
 * silently, which is why it is asserted rather than reasoned about.
 */

function renderHostId(string $html): string
{
    expect($html)->toMatch('/id="[0-9a-f]{8}\.table\.records\.1"/');

    preg_match('/id="([0-9a-f]{8})\.table\.records\.1"/', $html, $matches);

    return $matches[1];
}

/**
 * @return array<string, mixed>
 */
function renderedProps(string $html): array
{
    preg_match('/data-live-props-value="([^"]*)"/', $html, $matches);

    $props = json_decode(html_entity_decode($matches[1] ?? ''), true, flags: JSON_THROW_ON_ERROR);

    expect($props)->toBeArray();

    return $props;
}

it('dehydrates the same host id it stamped on the rows', function (): void {
    $twig = $this::getContainer()->get('twig');
    expect($twig)->toBeInstanceOf(Environment::class);

    $html = (string) $twig->createTemplate('<twig:RecordActionsTable />')->render();

    expect(renderedProps($html)['tableHostId'])->toBe(renderHostId($html));
});

it('dehydrates the writable props in the vocabulary of the elements bound to them', function (): void {
    $twig = $this::getContainer()->get('twig');
    expect($twig)->toBeInstanceOf(Environment::class);

    $html = (string) $twig->createTemplate('<twig:PaginatedSongsTable />')->render();
    $props = renderedProps($html);
    $crawler = new Crawler($html);

    $select = $crawler->filter('select.sy-pagination-per-page-select');
    $search = $crawler->filter('input.sy-ta-search-input');

    expect($props['tableRecordsPerPage'])->toBe('10')
        ->and($props['tableSearch'])->toBe('')
        ->and($select->attr('data-model'))->toBe('tableRecordsPerPage')
        ->and($select->filter('option[selected]')->attr('value'))->toBe($props['tableRecordsPerPage'])
        ->and($search->attr('data-model'))->toContain('|tableSearch')
        ->and($search->attr('value'))->toBeNull();
});

it('keeps the host id across a live round trip', function (): void {
    $component = $this->createLiveComponent('RecordActionsTable');

    $before = renderHostId((string) $component->render());

    $after = (string) $component
        ->call('mountAction', ['name' => 'edit', 'arguments' => [], 'context' => ['table' => true, 'recordKey' => '1']])
        ->render();

    expect(renderHostId($after))->toBe($before);
});
