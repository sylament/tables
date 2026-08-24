<?php

declare(strict_types=1);

use Sylament\Actions\Action;
use Sylament\Actions\ActionGroup;
use Sylament\Actions\BulkAction;
use Sylament\Tables\Enums\HeaderActionsPosition;
use Sylament\Tables\Table;
use Sylament\Tables\Tests\Fixtures\Concerns\TableHost;

it('registers header actions into the two flat maps, groups flattened', function (): void {
    $table = Table::make(new TableHost())
        ->headerActions([
            Action::make('create'),
            ActionGroup::make([BulkAction::make('archive')]),
        ]);

    expect($table->getHeaderActions())->toHaveCount(2)
        ->and(array_keys($table->getFlatActions()))->toBe(['create'])
        ->and(array_keys($table->getFlatBulkActions()))->toBe(['archive']);
});

it('evicts the previous header set from both flat maps on a second call', function (): void {
    $table = Table::make(new TableHost())
        ->headerActions([BulkAction::make('archive'), Action::make('create')]);

    expect($table->isSelectionEnabled())->toBeTrue();

    $table->headerActions([Action::make('create')]);

    expect($table->getHeaderActions())->toHaveCount(1)
        ->and($table->getFlatBulkActions())->toBe([])
        ->and($table->isSelectionEnabled())->toBeFalse();
});

it('leaves the toolbar set alone when the header set is replaced', function (): void {
    $table = Table::make(new TableHost())
        ->toolbarActions([$archive = BulkAction::make('archive')])
        ->headerActions([BulkAction::make('export')]);

    $table->headerActions([]);

    expect($table->getBulkAction('archive'))->toBe($archive)
        ->and($table->getBulkAction('export'))->toBeNull()
        ->and($table->isSelectionEnabled())->toBeTrue();
});

it('appends to the header set with pushHeaderActions', function (): void {
    $table = Table::make(new TableHost())
        ->headerActions([Action::make('create')])
        ->pushHeaderActions([ActionGroup::make([Action::make('import')])]);

    expect($table->getHeaderActions())->toHaveCount(2)
        ->and(array_keys($table->getFlatActions()))->toBe(['create', 'import']);
});

it('defaults the header actions position to Adaptive and takes it from either channel', function (): void {
    $default = Table::make(new TableHost())->headerActions([Action::make('create')]);
    $positional = Table::make(new TableHost())->headerActions([Action::make('create')], HeaderActionsPosition::Bottom);
    $setter = Table::make(new TableHost())->headerActionsPosition(fn (): HeaderActionsPosition => HeaderActionsPosition::Bottom);

    expect($default->getHeaderActionsPosition())->toBe(HeaderActionsPosition::Adaptive)
        ->and($positional->getHeaderActionsPosition())->toBe(HeaderActionsPosition::Bottom)
        ->and($setter->getHeaderActionsPosition())->toBe(HeaderActionsPosition::Bottom);
});

it('keeps a position already set when a later headerActions() call names none', function (): void {
    $table = Table::make(new TableHost())
        ->headerActions([Action::make('create')], HeaderActionsPosition::Bottom)
        ->headerActions([Action::make('import')]);

    expect($table->getHeaderActionsPosition())->toBe(HeaderActionsPosition::Bottom);
});

it('binds the table onto each pushed header action and group member', function (): void {
    $action = Action::make('create');
    $group = ActionGroup::make([$member = BulkAction::make('archive')]);

    $table = Table::make(new TableHost())->headerActions([$action, $group]);

    expect($action->getTable())->toBe($table)
        ->and($member->getTable())->toBe($table);
});

it('refuses a header member that is neither an action nor a group', function (): void {
    Table::make(new TableHost())->headerActions(['create']);
})->throws(InvalidArgumentException::class, 'Table header actions must be an instance of');
