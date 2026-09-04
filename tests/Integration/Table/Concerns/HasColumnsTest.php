<?php

declare(strict_types=1);

namespace Sylament\Tables\Tests\Integration\Table\Concerns;

use Sylament\Tables\Tests\Fixtures\Twig\Components\ColumnActionsTable;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;

uses(InteractsWithLiveComponents::class);

/*
 * The round trip the cell wrapper's `action` arm renders: a column carrying an
 * `Action` instance is mounted by name, so it resolves only because `columns()`
 * cached it on the table. The fixture's action appends to a `LiveProp`, so what
 * it was handed survives the round trip and is readable off the re-hydrated host.
 */

it('mounts a column action instance and calls it with the record bound', function (): void {
    $host = $this->createLiveComponent(ColumnActionsTable::class)
        ->call('mountAction', ['name' => 'view', 'arguments' => [], 'context' => ['recordKey' => '2', 'table' => true]])
        ->component();

    assert($host instanceof ColumnActionsTable);

    expect($host->calls)->toBe(['object:Let Down'])
        ->and($host->mountedActions)->toBe([]);
});

it('caches no action for a column whose action is a closure, so mounting its name unmounts', function (): void {
    $host = $this->createLiveComponent(ColumnActionsTable::class)
        ->call('mountAction', ['name' => 'title', 'arguments' => [], 'context' => ['recordKey' => '1', 'table' => true]])
        ->component();

    assert($host instanceof ColumnActionsTable);

    expect($host->calls)->toBe([])
        ->and($host->mountedActions)->toBe([]);
});
