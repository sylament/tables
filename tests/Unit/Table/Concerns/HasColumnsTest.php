<?php

declare(strict_types=1);

use Sylament\Actions\Action;
use Sylament\Tables\Columns\TextColumn;
use Sylament\Tables\Table;
use Sylament\Tables\Tests\Fixtures\Concerns\TableHost;

it('caches a column action instance into the flat action map, bound to the table', function (): void {
    $action = Action::make('view');

    $table = Table::make(new TableHost())
        ->columns([TextColumn::make('title')->action($action)]);

    expect($table->getAction('view'))->toBe($action)
        ->and($action->getTable())->toBe($table);
});

it('caches nothing for a column whose action is a closure or absent', function (): void {
    $table = Table::make(new TableHost())
        ->columns([
            TextColumn::make('title')->action(fn (): null => null),
            TextColumn::make('album')->action('handleAlbum'),
            TextColumn::make('id'),
        ]);

    expect($table->getFlatActions())->toBe([]);
});

it('keeps a replaced column set action cached, as Filament does', function (): void {
    $table = Table::make(new TableHost())
        ->columns([TextColumn::make('title')->action(Action::make('view'))])
        ->columns([TextColumn::make('album')->action(Action::make('audit'))]);

    expect(array_keys($table->getFlatActions()))->toBe(['view', 'audit'])
        ->and(array_keys($table->getColumns()))->toBe(['album']);
});

it('drops hidden and toggled-hidden columns and keeps the rest', function (): void {
    $host = new TableHost(fn (Table $table): Table => $table->columns([
        TextColumn::make('title'),
        TextColumn::make('slug')->hidden(),
        TextColumn::make('plays')->toggleable(),
    ]));
    $host->tableColumns = [['type' => 'column', 'name' => 'plays', 'isToggled' => false, 'isToggledHiddenByDefault' => false]];

    expect(array_keys($host->getTable()->getVisibleColumns()))->toBe(['title'])
        ->and(array_keys($host->getTable()->getColumns()))->toBe(['title', 'slug', 'plays']);
});

it('memoises the visible set until flushed or the columns change', function (): void {
    $asked = 0;
    $host = new TableHost(function (Table $table) use (&$asked): Table {
        // An arrow function would capture `$asked` by value, so the inner
        // closure would count against a copy the test cannot read.
        return $table->columns([
            TextColumn::make('title')->visible(function () use (&$asked): bool {
                $asked++;

                return true;
            }),
        ]);
    });
    $table = $host->getTable();

    $table->getVisibleColumns();
    $table->getVisibleColumns();

    // One walk asks each column twice — `isVisible()`, then `isHidden()` again
    // underneath `isToggledHidden()` → `isToggleable()`.
    expect($asked)->toBe(2);

    $table->flushCachedVisibleColumns();
    $table->getVisibleColumns();

    expect($asked)->toBe(4);

    $table->columns([TextColumn::make('slug')]);

    expect(array_keys($table->getVisibleColumns()))->toBe(['slug'])
        ->and($asked)->toBe(4);
});
