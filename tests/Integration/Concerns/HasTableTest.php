<?php

declare(strict_types=1);

namespace Sylament\Tables\Tests\Integration\Concerns;

use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;
use Twig\Environment;

uses(InteractsWithLiveComponents::class);

/*
 * Pins the one thing `getTableHostId()` exists for: the id stamped on the rows
 * is the id the next request hydrates back. It only holds because Symfony's
 * `PropertyAccessor` reads `$tableHostId` through the same-named getter, so the
 * dehydration pass itself mints the id — see the divergence in
 * `docs/progress/tables/has-table.md`. Renaming either half breaks this
 * silently, which is why it is asserted rather than reasoned about.
 */

function renderHostId(string $html): string
{
    expect($html)->toMatch('/id="[0-9a-f]{8}\.table\.records\.1"/');

    preg_match('/id="([0-9a-f]{8})\.table\.records\.1"/', $html, $matches);

    return $matches[1];
}

it('dehydrates the same host id it stamped on the rows', function (): void {
    $twig = $this::getContainer()->get('twig');
    expect($twig)->toBeInstanceOf(Environment::class);

    $html = (string) $twig->createTemplate('<twig:RecordActionsTable />')->render();

    preg_match('/data-live-props-value="([^"]*)"/', $html, $matches);
    $props = json_decode(html_entity_decode($matches[1] ?? ''), true, flags: JSON_THROW_ON_ERROR);

    expect($props)->toBeArray()
        ->and($props['tableHostId'])->toBe(renderHostId($html));
});

it('keeps the host id across a live round trip', function (): void {
    $component = $this->createLiveComponent('RecordActionsTable');

    $before = renderHostId((string) $component->render());

    $after = (string) $component
        ->call('mountAction', ['name' => 'edit', 'arguments' => [], 'context' => ['table' => true, 'recordKey' => '1']])
        ->render();

    expect(renderHostId($after))->toBe($before);
});
