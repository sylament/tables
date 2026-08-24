<?php

declare(strict_types=1);

use Sylament\Actions\Action;
use Sylament\Actions\ActionGroup;
use Sylament\Actions\BulkAction;
use Sylament\Tables\Table;
use Sylament\Tables\Tests\Fixtures\Concerns\TableHost;

it('registers toolbar actions into the two flat maps, groups flattened', function (): void {
    $table = Table::make(new TableHost())
        ->toolbarActions([
            Action::make('create'),
            ActionGroup::make([BulkAction::make('archive')]),
        ]);

    expect($table->getToolbarActions())->toHaveCount(2)
        ->and(array_keys($table->getFlatActions()))->toBe(['create'])
        ->and(array_keys($table->getFlatBulkActions()))->toBe(['archive'])
        ->and($table->hasBulkAction('archive'))->toBeTrue()
        ->and($table->hasAction('archive'))->toBeFalse();
});

it('evicts the previous toolbar set from both flat maps on a second call', function (): void {
    $table = Table::make(new TableHost())
        ->toolbarActions([BulkAction::make('archive'), Action::make('create')]);

    expect($table->isSelectionEnabled())->toBeTrue();

    $table->toolbarActions([Action::make('create')]);

    expect($table->getToolbarActions())->toHaveCount(1)
        ->and($table->getFlatBulkActions())->toBe([])
        ->and(array_keys($table->getFlatActions()))->toBe(['create'])
        ->and($table->isSelectionEnabled())->toBeFalse();
});

it('leaves the record actions alone when the toolbar set is replaced', function (): void {
    $table = Table::make(new TableHost())
        ->recordActions([$recordAction = Action::make('delete')])
        ->toolbarActions([Action::make('create')]);

    $table->toolbarActions([]);

    expect($table->getAction('delete'))->toBe($recordAction)
        ->and($table->getAction('create'))->toBeNull();
});

it('appends to the toolbar set with pushToolbarActions', function (): void {
    $table = Table::make(new TableHost())
        ->toolbarActions([BulkAction::make('archive')])
        ->pushToolbarActions([BulkAction::make('delete')]);

    expect($table->getToolbarActions())->toHaveCount(2)
        ->and(array_keys($table->getFlatBulkActions()))->toBe(['archive', 'delete']);
});

it('binds the table onto each pushed toolbar action and group', function (): void {
    $action = BulkAction::make('archive');
    $group = ActionGroup::make([$member = BulkAction::make('delete')]);

    $table = Table::make(new TableHost())->toolbarActions([$action, $group]);

    expect($action->getTable())->toBe($table)
        ->and($group->getTable())->toBe($table)
        ->and($member->getTable())->toBe($table);
});

it('refuses a toolbar member that is neither an action nor a group', function (): void {
    Table::make(new TableHost())->toolbarActions(['archive']);
})->throws(InvalidArgumentException::class, 'Table toolbar actions must be an instance of');
