<?php

declare(strict_types=1);

namespace Sylament\Tables\Tests\Unit;

use Sylament\Actions\Action;
use Sylament\Actions\ActionGroup;
use Sylament\Actions\BulkAction;
use Sylament\Tables\Table;
use Sylament\Tables\Tests\Fixtures\Concerns\TableActionsHost;
use Sylament\Tables\Tests\Fixtures\Concerns\TableHost;

it('binds a table and falls back through the group', function (): void {
    $table = Table::make(new TableHost());
    $action = Action::make('edit');

    expect($action->hasTable())->toBeFalse();

    $group = ActionGroup::make([$action])->table($table);

    expect($action->getTable())->toBe($table)
        ->and($action->hasTable())->toBeTrue()
        ->and($group->getTable())->toBe($table);
});

it('lets an action table binding win over its group', function (): void {
    $own = Table::make(new TableHost());
    $action = Action::make('edit')->table($own);

    ActionGroup::make([$action])->table(Table::make(new TableHost()));

    expect($action->getTable())->toBe($own);
});

it('emits the table flag beside the record key for a table-bound action', function (): void {
    $action = Action::make('edit')
        ->table(Table::make(new TableHost()))
        ->record(['__key' => '7']);

    expect($action->getContext())->toBe(['recordKey' => '7', 'table' => true]);
});

it('drops the table flag on a nested frame', function (): void {
    $action = Action::make('child')
        ->table(Table::make(new TableHost()))
        ->record(['__key' => '7'])
        ->parentAction(Action::make('parent'));

    expect($action->getContext())->toBe(['recordKey' => '7']);
});

it('resolves the live component through the bound table', function (): void {
    $host = new TableActionsHost();

    $action = Action::make('edit')->table(Table::make($host));

    expect($action->getLiveComponent())->toBe($host);
});

it('emits the bulk flag beside the table flag for a bulk table action', function (): void {
    $action = BulkAction::make('delete')->table(Table::make(new TableHost()));

    expect($action->getContext())->toBe(['table' => true, 'bulk' => true]);
});

it('emits no bulk flag for a plain table action', function (): void {
    $action = Action::make('edit')->table(Table::make(new TableHost()));

    expect($action->getContext())->toBe(['table' => true]);
});

it('drops the bulk flag with the table flag on a nested frame', function (): void {
    $action = BulkAction::make('delete')
        ->table(Table::make(new TableHost()))
        ->record(['__key' => '7'])
        ->parentAction(Action::make('parent'));

    expect($action->getContext())->toBe(['recordKey' => '7']);
});
