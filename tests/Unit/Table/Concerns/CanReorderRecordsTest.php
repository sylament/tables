<?php

declare(strict_types=1);

use Sylament\Actions\Action;
use Sylament\Tables\Table;
use Sylament\Tables\Tests\Fixtures\Concerns\TableHost;

it('is not reorderable until a column names one', function (): void {
    $bare = new TableHost()->getTable();
    $columned = new TableHost(fn (Table $table): Table => $table->reorderable('sort'))->getTable();

    expect($bare->isReorderable())->toBeFalse()
        ->and($bare->getReorderColumn())->toBeNull()
        ->and($columned->isReorderable())->toBeTrue()
        ->and($columned->getReorderColumn())->toBe('sort');
});

it('defaults the direction to ascending and takes the third argument', function (): void {
    $ascending = new TableHost(fn (Table $table): Table => $table->reorderable('sort'))->getTable();
    $descending = new TableHost(fn (Table $table): Table => $table->reorderable('sort', direction: 'desc'))->getTable();

    expect($ascending->getReorderDirection())->toBe('asc')
        ->and($descending->getReorderDirection())->toBe('desc');
});

it('keeps a prior false condition when a later call names only a column', function (): void {
    $table = new TableHost(fn (Table $table): Table => $table
        ->reorderable('sort', false)
        ->reorderable('sort'))->getTable();

    expect($table->isReorderable())->toBeFalse();
});

it('clears the column and the direction on a bare call', function (): void {
    $table = new TableHost(fn (Table $table): Table => $table
        ->reorderable('sort', direction: 'desc')
        ->reorderable())->getTable();

    expect($table->getReorderColumn())->toBeNull()
        ->and($table->getReorderDirection())->toBe('asc')
        ->and($table->isReorderable())->toBeFalse();
});

it('resolves the column, the condition and the direction through closures', function (): void {
    $table = new TableHost(fn (Table $table): Table => $table->reorderable(
        fn (Table $table): string => $table->getRecordLabel() === 'record' ? 'sort' : 'other',
        fn (): bool => true,
        fn (): string => 'desc',
    ))->getTable();

    expect($table->getReorderColumn())->toBe('sort')
        ->and($table->getReorderDirection())->toBe('desc')
        ->and($table->isReorderable())->toBeTrue();
});

it('refuses reordering the authorization seam denies', function (): void {
    $table = new TableHost(fn (Table $table): Table => $table
        ->reorderable('sort')
        ->authorizeReorder(false))->getTable();

    expect($table->isReorderAuthorized())->toBeFalse()
        ->and($table->isReorderable())->toBeFalse();
});

it('reports the host reordering flag, gated by the definition', function (): void {
    $reorderable = new TableHost(fn (Table $table): Table => $table->reorderable('sort'));
    $reorderable->isTableReordering = true;

    $unreorderable = new TableHost();
    $unreorderable->isTableReordering = true;

    expect($reorderable->getTable()->isReordering())->toBeTrue()
        ->and($unreorderable->getTable()->isReordering())->toBeFalse();
});

it('builds the trigger action, toggling its label and icon by state', function (): void {
    $table = new TableHost(fn (Table $table): Table => $table->reorderable('sort'))->getTable();

    $enable = $table->getReorderRecordsTriggerAction(false);
    $disable = $table->getReorderRecordsTriggerAction(true);

    expect($enable->getName())->toBe('reorderRecords')
        ->and($enable->getLabel())->toBe('Reorder records')
        ->and($enable->getIcon())->toBe('heroicons:arrows-up-down')
        ->and($enable->getColor())->toBe('gray')
        ->and($enable->getClientClickHandler())->toBe('toggleTableReordering')
        ->and($enable->getTable())->toBe($table)
        ->and($disable->getLabel())->toBe('Finish reordering records')
        ->and($disable->getIcon())->toBe('heroicons:check');
});

it('passes the action and the state to the trigger customization, falling back to the untouched action', function (): void {
    $seen = [];

    $table = new TableHost(function (Table $table) use (&$seen): Table {
        return $table
            ->reorderable('sort')
            ->reorderRecordsTriggerAction(function (Action $action, bool $isReordering) use (&$seen): ?Action {
                $seen[] = $isReordering;

                return $isReordering ? $action->label('Done') : null;
            });
    })->getTable();

    expect($table->getReorderRecordsTriggerAction(false)->getLabel())->toBe('Reorder records')
        ->and($table->getReorderRecordsTriggerAction(true)->getLabel())->toBe('Done')
        ->and($seen)->toBe([false, true]);
});

it('carries the shared reorder animation duration', function (): void {
    $table = new TableHost(fn (Table $table): Table => $table->reorderable('sort'))->getTable();
    $slow = new TableHost(fn (Table $table): Table => $table->reorderAnimationDuration(0))->getTable();

    expect($table->getReorderAnimationDuration())->toBe(300)
        ->and($slow->getReorderAnimationDuration())->toBe(0);
});

it('brackets the write with the before and after hooks, injecting the order', function (): void {
    $seen = [];

    $table = new TableHost(function (Table $table) use (&$seen): Table {
        return $table
            ->reorderable('sort')
            ->beforeReordering(function (array $order) use (&$seen): void {
                $seen['before'] = $order;
            })
            ->afterReordering(function (array $order) use (&$seen): void {
                $seen['after'] = $order;
            });
    })->getTable();

    $table->callBeforeReordering(['2', '1']);
    $table->callAfterReordering(['2', '1']);

    expect($seen)->toBe(['before' => ['2', '1'], 'after' => ['2', '1']]);
});
