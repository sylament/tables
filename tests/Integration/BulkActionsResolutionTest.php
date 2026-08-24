<?php

declare(strict_types=1);

namespace Sylament\Tables\Tests\Integration;

use Sylament\Actions\BulkAction;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;

uses(InteractsWithLiveComponents::class);

/*
 * The bulk arm of actions' `resolveTableAction()`, exercised across the package
 * boundary it needs: `getBulkAction()` lives on tables' `Table`, so only a real
 * table host can prove which of the two maps a mounted descriptor read. The
 * fixture registers the same name in both, so the resolved action's own modal
 * heading names the map it came from.
 */

it('resolves a bulk descriptor from the table bulk map', function (): void {
    $host = $this->createLiveComponent('BulkResolutionTable')
        ->call('mountAction', ['name' => 'archive', 'arguments' => [], 'context' => ['table' => true, 'bulk' => true]])
        ->component();

    $action = $host->getMountedAction();

    expect($action)->toBeInstanceOf(BulkAction::class)
        ->and($action?->getModalHeading())->toBe('Bulk archive');
});

it('still resolves a record descriptor from the record map', function (): void {
    $host = $this->createLiveComponent('BulkResolutionTable')
        ->call('mountAction', ['name' => 'archive', 'arguments' => [], 'context' => ['table' => true, 'recordKey' => '1']])
        ->component();

    $action = $host->getMountedAction();

    expect($action)->not->toBeInstanceOf(BulkAction::class)
        ->and($action?->getModalHeading())->toBe('Record archive');
});

it('falls through to the record map for a bulk descriptor the bulk map does not know', function (): void {
    $host = $this->createLiveComponent('BulkResolutionTable')
        ->call('mountAction', ['name' => 'archive', 'arguments' => [], 'context' => ['table' => true, 'bulk' => true]])
        ->component();

    expect($host->getMountedAction())->toBeInstanceOf(BulkAction::class);

    $recordOnly = $this->createLiveComponent('RecordActionsTable')
        ->call('mountAction', ['name' => 'delete', 'arguments' => [], 'context' => ['table' => true, 'bulk' => true, 'recordKey' => '1']])
        ->component();

    expect($recordOnly->getMountedAction()?->getName())->toBe('delete')
        ->and($recordOnly->getMountedAction())->not->toBeInstanceOf(BulkAction::class);
});

it('unmounts gracefully for a bulk name neither map knows', function (): void {
    $host = $this->createLiveComponent('BulkResolutionTable')
        ->call('mountAction', ['name' => 'vaporise', 'arguments' => [], 'context' => ['table' => true, 'bulk' => true]])
        ->component();

    expect($host->mountedActions)->toBe([])
        ->and($host->getMountedAction())->toBeNull();
});
