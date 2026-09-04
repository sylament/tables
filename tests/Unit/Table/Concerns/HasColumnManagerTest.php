<?php

declare(strict_types=1);

use Sylament\Actions\Action;
use Sylament\Tables\Columns\TextColumn;
use Sylament\Tables\Enums\ColumnManagerLayout;
use Sylament\Tables\Enums\ColumnManagerResetActionPosition;
use Sylament\Tables\Table;
use Sylament\Tables\Tests\Fixtures\Concerns\TableHost;

it('derives the manager from toggleable or reorderable columns unless told otherwise', function (): void {
    $plain = new TableHost(fn (Table $table): Table => $table->columns([TextColumn::make('title')]));
    $toggleable = new TableHost(fn (Table $table): Table => $table->columns([TextColumn::make('title')->toggleable()]));
    $reorderable = new TableHost(fn (Table $table): Table => $table->columns([TextColumn::make('title')])->reorderableColumns());
    $refused = new TableHost(fn (Table $table): Table => $table->columns([TextColumn::make('title')->toggleable()])->columnManager(false));

    expect($plain->getTable()->hasColumnManager())->toBeFalse()
        ->and($toggleable->getTable()->hasColumnManager())->toBeTrue()
        ->and($reorderable->getTable()->hasColumnManager())->toBeTrue()
        ->and($refused->getTable()->hasColumnManager())->toBeFalse();
});

it('defers and persists by default, in a dropdown with the reset in the header', function (): void {
    $table = new TableHost()->getTable();

    expect($table->hasDeferredColumnManager())->toBeTrue()
        ->and($table->persistsColumnsInSession())->toBeTrue()
        ->and($table->hasReorderableColumns())->toBeFalse()
        ->and($table->getColumnManagerLayout())->toBe(ColumnManagerLayout::Dropdown)
        ->and($table->getColumnManagerResetActionPosition())->toBe(ColumnManagerResetActionPosition::Header)
        ->and($table->getColumnManagerColumns())->toBe(1)
        ->and($table->getColumnManagerWidth())->toBeNull()
        ->and($table->getColumnManagerMaxHeight())->toBeNull();
});

it('takes every manager setting from a closure as readily as a literal', function (): void {
    $table = new TableHost(fn (Table $table): Table => $table
        ->deferColumnManager(fn (): bool => false)
        ->persistColumnsInSession(fn (): bool => false)
        ->reorderableColumns(fn (): bool => true)
        ->columnManagerLayout(fn (): ColumnManagerLayout => ColumnManagerLayout::Modal)
        ->columnManagerResetActionPosition(fn (): ColumnManagerResetActionPosition => ColumnManagerResetActionPosition::Footer)
        ->columnManagerColumns(fn (): array => ['sm' => 2])
        ->columnManagerWidth(fn (): string => '42rem')
        ->columnManagerMaxHeight(fn (): string => '20rem'))->getTable();

    expect($table->hasDeferredColumnManager())->toBeFalse()
        ->and($table->persistsColumnsInSession())->toBeFalse()
        ->and($table->hasReorderableColumns())->toBeTrue()
        ->and($table->getColumnManagerLayout())->toBe(ColumnManagerLayout::Modal)
        ->and($table->getColumnManagerResetActionPosition())->toBe(ColumnManagerResetActionPosition::Footer)
        ->and($table->getColumnManagerColumns())->toBe(['sm' => 2])
        ->and($table->getColumnManagerWidth())->toBe('42rem')
        ->and($table->getColumnManagerMaxHeight())->toBe('20rem');
});

it('gates the two chrome branches on the layout and on the manager itself', function (): void {
    $dropdown = new TableHost(fn (Table $table): Table => $table->columns([TextColumn::make('title')->toggleable()]))->getTable();
    $modal = new TableHost(fn (Table $table): Table => $table->columns([TextColumn::make('title')->toggleable()])->columnManagerLayout(ColumnManagerLayout::Modal))->getTable();
    $none = new TableHost()->getTable();

    expect($dropdown->hasColumnManagerDropdown())->toBeTrue()
        ->and($dropdown->hasColumnManagerModal())->toBeFalse()
        ->and($modal->hasColumnManagerDropdown())->toBeFalse()
        ->and($modal->hasColumnManagerModal())->toBeTrue()
        ->and($none->hasColumnManagerDropdown())->toBeFalse()
        ->and($none->hasColumnManagerModal())->toBeFalse();
});

it('opens client-side and, under the modal layout, commands the dialog', function (): void {
    $dropdown = new TableHost()->getTable()->getColumnManagerTriggerAction();
    $modal = new TableHost(fn (Table $table): Table => $table
        ->columns([TextColumn::make('title')->toggleable()])
        ->columnManagerLayout(ColumnManagerLayout::Modal))->getTable();
    $managerless = new TableHost(fn (Table $table): Table => $table->columnManagerLayout(ColumnManagerLayout::Modal))->getTable();

    expect($dropdown->getName())->toBe('openColumnManager')
        ->and($dropdown->isLiveComponentClickHandlerEnabled())->toBeFalse()
        ->and($dropdown->getExtraAttributes())->not->toHaveKey('command')
        ->and($modal->getColumnManagerModalId())->toEndWith('.table.column-manager')
        ->and($modal->getColumnManagerTriggerAction()->getExtraAttributes())->toMatchArray(['command' => 'show-modal', 'commandfor' => $modal->getColumnManagerModalId()])
        ->and($managerless->getColumnManagerTriggerAction()->getExtraAttributes())->not->toHaveKey('command');
});

it('shows the apply action only while deferring and hands both actions to their closures', function (): void {
    $live = new TableHost(fn (Table $table): Table => $table->deferColumnManager(false))->getTable();
    $customised = new TableHost(fn (Table $table): Table => $table
        ->columns([TextColumn::make('title')->toggleable()])
        ->columnManagerLayout(ColumnManagerLayout::Modal)
        ->columnManagerTriggerAction(fn (Action $action): Action => $action->label('Columns…')->extraAttributes(['data-probe' => 'yes']))
        ->columnManagerApplyAction(fn (Action $action): Action => $action->label('Go')))->getTable();

    expect(new TableHost()->getTable()->getColumnManagerApplyAction()->isVisible())->toBeTrue()
        ->and($live->getColumnManagerApplyAction()->isVisible())->toBeFalse()
        ->and($customised->getColumnManagerTriggerAction()->getLabel())->toBe('Columns…')
        ->and($customised->getColumnManagerApplyAction()->getLabel())->toBe('Go')
        ->and($customised->getColumnManagerApplyAction()->getAction())->toBe('applyTableColumnManager')
        ->and($customised->getColumnManagerTriggerAction()->getExtraAttributes())->toMatchArray([
            'data-probe' => 'yes',
            'command' => 'show-modal',
            'commandfor' => $customised->getColumnManagerModalId(),
        ]);
});
