<?php

declare(strict_types=1);

use Sylament\Actions\Action;
use Sylament\Actions\BulkAction;
use Sylament\Actions\BulkActionGroup;
use Sylament\Tables\Enums\RecordCheckboxPosition;
use Sylament\Tables\Table;
use Sylament\Tables\Tests\Fixtures\Concerns\TableHost;
use Sylament\Tables\Tests\Fixtures\Records\Song;

it('selects nothing, tracks deselections and checkboxes lead, out of the box', function (): void {
    $table = Table::make(new TableHost());

    expect($table->isSelectionEnabled())->toBeFalse()
        ->and($table->selectsCurrentPageOnly())->toBeFalse()
        ->and($table->canTrackDeselectedRecords())->toBeTrue()
        ->and($table->checksIfRecordIsSelectable())->toBeFalse()
        ->and($table->getRecordCheckboxPosition())->toBe(RecordCheckboxPosition::BeforeCells)
        ->and($table->getFlatBulkActions())->toBe([]);
});

it('turns selection on for a visible bulk action, and off again when every one is hidden', function (): void {
    $visible = Table::make(new TableHost())->toolbarActions([BulkAction::make('archive')]);
    $hidden = Table::make(new TableHost())->toolbarActions([BulkAction::make('archive')->hidden()]);
    $recordAction = Table::make(new TableHost())->toolbarActions([Action::make('create')]);

    expect($visible->isSelectionEnabled())->toBeTrue()
        ->and($hidden->isSelectionEnabled())->toBeFalse()
        ->and($recordAction->isSelectionEnabled())->toBeFalse();
});

it('lets an explicit selectable() bool win over the bulk actions in both directions', function (): void {
    $off = Table::make(new TableHost())
        ->toolbarActions([BulkAction::make('archive')])
        ->selectable(false);
    $on = Table::make(new TableHost())->selectable();

    expect($off->isSelectionEnabled())->toBeFalse()
        ->and($on->isSelectionEnabled())->toBeTrue();
});

it('forces current-page-only selection on a records() table, whatever the setter says', function (): void {
    $records = Table::make(new TableHost())->records(fn (): array => []);

    expect($records->selectsCurrentPageOnly())->toBeTrue();

    expect(Table::make(new TableHost())->selectCurrentPageOnly()->selectsCurrentPageOnly())->toBeTrue()
        ->and(Table::make(new TableHost())->selectCurrentPageOnly(false)->selectsCurrentPageOnly())->toBeFalse();
});

it('force-disables deselect tracking when the table does not deselect on filtering', function (): void {
    $table = Table::make(new TableHost());

    expect($table->shouldDeselectAllRecordsWhenFiltered())->toBeTrue()
        ->and($table->canTrackDeselectedRecords())->toBeTrue();

    $table->deselectAllRecordsWhenFiltered(false);

    expect($table->canTrackDeselectedRecords())->toBeFalse();

    $table->deselectAllRecordsWhenFiltered()->trackDeselectedRecords(false);

    expect($table->canTrackDeselectedRecords())->toBeFalse();
});

it('calls every record selectable until a callback says otherwise', function (): void {
    $table = Table::make(new TableHost());
    $song = new Song('Airbag', 'OK Computer', id: 1);

    expect($table->isRecordSelectable($song))->toBeTrue()
        ->and($table->isRecordSelectable(['__key' => '1']))->toBeTrue();

    $table->checkIfRecordIsSelectableUsing(fn (Song $record): bool => $record->id === 1);

    expect($table->checksIfRecordIsSelectable())->toBeTrue()
        ->and($table->isRecordSelectable($song))->toBeTrue()
        ->and($table->isRecordSelectable(new Song('Let Down', 'OK Computer', id: 2)))->toBeFalse();
});

it('injects the record by name, which is the only arm an array record has', function (): void {
    $seen = [];

    $table = Table::make(new TableHost())
        ->checkIfRecordIsSelectableUsing(function (mixed $record) use (&$seen): bool {
            $seen[] = $record;

            return true;
        });

    $table->isRecordSelectable($record = ['__key' => '7', 'title' => 'Airbag']);

    expect($seen)->toBe([$record]);
});

it('injects an object record by its own class, whatever the parameter is called', function (): void {
    $table = Table::make(new TableHost())
        ->checkIfRecordIsSelectableUsing(fn (Song $selected): bool => $selected->id === 1);

    expect($table->isRecordSelectable(new Song('Airbag', 'OK Computer', id: 1)))->toBeTrue()
        ->and($table->isRecordSelectable(new Song('Let Down', 'OK Computer', id: 2)))->toBeFalse();
});

it('keeps checksIfRecordIsSelectable a presence test, never an evaluation', function (): void {
    $evaluations = 0;

    $table = Table::make(new TableHost())
        ->checkIfRecordIsSelectableUsing(function () use (&$evaluations): bool {
            $evaluations++;

            return true;
        });

    $table->checksIfRecordIsSelectable();

    expect($evaluations)->toBe(0);
});

it('fills the toolbar actions through the deprecated bulk aliases', function (): void {
    $table = Table::make(new TableHost())
        ->bulkActions([$archive = BulkAction::make('archive')])
        ->pushBulkActions([$delete = BulkAction::make('delete')]);

    expect($table->getToolbarActions())->toBe([$archive, $delete])
        ->and(array_keys($table->getFlatBulkActions()))->toBe(['archive', 'delete'])
        ->and($table->isSelectionEnabled())->toBeTrue();
});

it('wraps grouped bulk actions in a BulkActionGroup', function (): void {
    $table = Table::make(new TableHost())->groupedBulkActions([$archive = BulkAction::make('archive')]);

    $group = $table->getToolbarActions()[0];

    expect($group)->toBeInstanceOf(BulkActionGroup::class)
        ->and($table->getBulkAction('archive'))->toBe($archive)
        ->and($table->isSelectionEnabled())->toBeTrue();
});

it('selects groups only when told to, closure included', function (): void {
    $default = new TableHost()->getTable();
    $groupsOnly = new TableHost(fn (Table $table): Table => $table->selectGroupsOnly())->getTable();
    $closured = new TableHost(fn (Table $table): Table => $table->selectGroupsOnly(fn (): bool => false))->getTable();

    expect($default->selectsGroupsOnly())->toBeFalse()
        ->and($groupsOnly->selectsGroupsOnly())->toBeTrue()
        ->and($closured->selectsGroupsOnly())->toBeFalse()
        // It removes the page-wide affordances and nothing else, so selection
        // itself and the checkbox column stay exactly as they were.
        ->and($groupsOnly->isSelectionEnabled())->toBe($default->isSelectionEnabled());
});

it('takes the checkbox position from the setter, closure included', function (): void {
    expect(Table::make(new TableHost())->recordCheckboxPosition(RecordCheckboxPosition::AfterCells)->getRecordCheckboxPosition())
        ->toBe(RecordCheckboxPosition::AfterCells)
        ->and(Table::make(new TableHost())->recordCheckboxPosition(fn (): RecordCheckboxPosition => RecordCheckboxPosition::AfterCells)->getRecordCheckboxPosition())
        ->toBe(RecordCheckboxPosition::AfterCells);
});

it('delegates the selectable-records count to the host', function (): void {
    $host = new TableHost(fn (Table $table): Table => $table->records(fn (): array => [
        ['__key' => '1'],
        ['__key' => '2'],
    ]));

    expect($host->getTable()->getAllSelectableRecordsCount())->toBe(2);
});
