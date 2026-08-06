<?php

declare(strict_types=1);

use Sylament\Actions\Action;
use Sylament\Actions\ActionGroup;
use Sylament\Support\Enums\Size;
use Sylament\Tables\Enums\RecordActionsPosition;
use Sylament\Tables\Table;
use Sylament\Tables\Tests\Fixtures\Concerns\TableHost;

it('registers record actions into one flat name-keyed map, groups flattened', function (): void {
    $table = Table::make(new TableHost())
        ->recordActions([
            Action::make('edit'),
            ActionGroup::make([Action::make('delete')]),
        ]);

    expect($table->getAction('edit'))->toBeInstanceOf(Action::class)
        ->and($table->getAction('delete'))->toBeInstanceOf(Action::class)
        ->and($table->hasAction('missing'))->toBeFalse()
        ->and(array_keys($table->getFlatActions()))->toBe(['edit', 'delete']);
});

it('resets the record action set on a second call', function (): void {
    $table = Table::make(new TableHost())
        ->recordActions([Action::make('edit')])
        ->recordActions([Action::make('delete')]);

    expect($table->getRecordActions())->toHaveCount(1)
        ->and($table->getRecordActions()[0]->getName())->toBe('delete');
});

it('appends to the record action set with pushRecordActions', function (): void {
    $table = Table::make(new TableHost())
        ->recordActions([Action::make('edit')])
        ->pushRecordActions(ActionGroup::make([Action::make('delete')]));

    expect($table->getRecordActions())->toHaveCount(2)
        ->and(array_keys($table->getFlatRecordActions()))->toBe(['edit', 'delete']);
});

it('defaults ungrouped record actions to the link template at small size, losing to explicit choices', function (): void {
    $defaulted = Action::make('edit');
    $explicit = Action::make('view')->size(Size::Large)->button();

    Table::make(new TableHost())->recordActions([$defaulted, $explicit]);

    expect($defaulted->getTemplate())->toBe(Action::LINK_TEMPLATE)
        ->and($defaulted->getSize())->toBe(Size::Small)
        ->and($explicit->getTemplate())->toBe(Action::BUTTON_TEMPLATE)
        ->and($explicit->getSize())->toBe(Size::Large);
});

it('binds the table onto each pushed action and group', function (): void {
    $action = Action::make('edit');
    $group = ActionGroup::make([$member = Action::make('delete')]);

    $table = Table::make(new TableHost())->recordActions([$action, $group]);

    expect($action->getTable())->toBe($table)
        ->and($member->getTable())->toBe($table);
});

it('defaults the position to AfterColumns and group dropdown placement by side', function (): void {
    $after = Table::make(new TableHost())->recordActions([ActionGroup::make([Action::make('delete')])]);
    $before = Table::make(new TableHost())->recordActions([ActionGroup::make([Action::make('delete')])], position: RecordActionsPosition::BeforeColumns);

    expect($after->getRecordActionsPosition())->toBe(RecordActionsPosition::AfterColumns)
        ->and($after->getRecordActions()[0]->getDropdownPlacement())->toBe('bottom-end')
        ->and($before->getRecordActionsPosition())->toBe(RecordActionsPosition::BeforeColumns)
        ->and($before->getRecordActions()[0]->getDropdownPlacement())->toBe('bottom-start');
});

it('evaluates a closure record actions position with the table injected', function (): void {
    $table = Table::make(new TableHost())
        ->recordActionsPosition(fn (Table $table): RecordActionsPosition => $table->hasAction('edit')
            ? RecordActionsPosition::AfterCells
            : RecordActionsPosition::BeforeCells);

    expect($table->getRecordActionsPosition())->toBe(RecordActionsPosition::BeforeCells);

    $table->recordActions([Action::make('edit')]);

    expect($table->getRecordActionsPosition())->toBe(RecordActionsPosition::AfterCells);
});

it('rejects a non-action member', function (): void {
    Table::make(new TableHost())->recordActions(['not-an-action']);
})->throws(InvalidArgumentException::class);
