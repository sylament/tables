<?php

declare(strict_types=1);

namespace Sylament\Tables\Tests\Integration;

use Doctrine\ORM\EntityManagerInterface;
use Sylament\Tables\Tests\Fixtures\Doctrine\Entity\Song;
use Sylament\Tables\Tests\Fixtures\Doctrine\EntityManagerFactory;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;

uses(InteractsWithLiveComponents::class);

/*
 * The SUT spans two packages: the branch under test lives in actions'
 * `HasActions::resolveActions()` / `resolveTableAction()`, but only a table host
 * can exercise it — so the file sits here rather than mirroring `src/`, and the
 * src-mirror rule is deliberately relaxed for it.
 *
 * These tests are also the rename-pin for the guarded soft reference: actions
 * names `Sylament\Tables\Contracts\InteractsWithTable` in a string-free
 * `instanceof` with no composer `require` behind it, so a renamed or moved
 * contract dead-ends the branch silently — here it fails loudly instead.
 */

it('resolves a table-context mount from the table and binds the record at resolve time', function (): void {
    $component = $this->createLiveComponent('RecordActionsTable')
        ->call('mountAction', ['name' => 'delete', 'arguments' => [], 'context' => ['table' => true, 'recordKey' => '2']]);

    expect((string) $component->render())->toContain('Delete &quot;Let Down&quot;?');
});

it('unmounts gracefully when the record key no longer resolves', function (): void {
    $component = $this->createLiveComponent('RecordActionsTable')
        ->call('mountAction', ['name' => 'delete', 'arguments' => [], 'context' => ['table' => true, 'recordKey' => '99']]);

    expect($component->component()->mountedActions)->toBe([]);
});

it('unmounts gracefully when the record key is not a scalar', function (): void {
    $component = $this->createLiveComponent('RecordActionsTable')
        ->call('mountAction', ['name' => 'delete', 'arguments' => [], 'context' => ['table' => true, 'recordKey' => ['nested' => 'array']]]);

    expect($component->component()->mountedActions)->toBe([]);
});

it('unmounts gracefully for an action name the table does not know', function (): void {
    $component = $this->createLiveComponent('RecordActionsTable')
        ->call('mountAction', ['name' => 'promote', 'arguments' => [], 'context' => ['table' => true, 'recordKey' => '1']]);

    expect($component->component()->mountedActions)->toBe([]);
});

it('re-renders the table without the row after a confirmed delete', function (): void {
    $component = $this->createLiveComponent('RecordActionsTable')
        ->call('mountAction', ['name' => 'delete', 'arguments' => [], 'context' => ['table' => true, 'recordKey' => '2']])
        ->call('callMountedAction');

    $html = (string) $component->render();

    expect($html)->toContain('Airbag')
        ->not->toContain('Let Down');
});

/*
 * The D15 pin: `callMountedAction()` flushes a table host's records cache in a
 * `finally`, so a write reaches the render that follows it even when something
 * populated the cache first. The write has to change the *set* of rows — a
 * mutation of the bound record would show through the stale cache too, since the
 * identity map hands the query lane the very instance the action mutated, and the
 * confirmation is what keeps the write in the `callMountedAction()` request.
 *
 * Kernel reboot is off for `PrebuiltDeleteActionTest`'s reason: the fixture
 * database is in-memory, so a reboot between the two requests would hand the
 * second a freshly-migrated, empty one.
 */
it('re-renders a record action write that followed a read of the records', function (): void {
    $client = $this::getContainer()->get('test.client');
    $client->disableReboot();

    EntityManagerFactory::seed(
        $this::getContainer()->get(EntityManagerInterface::class),
        new Song(1, 'Airbag', 100),
        new Song(2, 'Let Down', 200),
    );

    $component = $this->createLiveComponent('QueryRecordActionsTable', client: $client)
        ->call('mountAction', ['name' => 'duplicate', 'arguments' => [], 'context' => ['table' => true, 'recordKey' => '2']])
        ->call('callMountedAction');

    expect((string) $component->render())->toContain('Let Down (copy)');
});
