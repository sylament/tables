<?php

declare(strict_types=1);

use Sylament\Tables\Columns\Column;
use Sylament\Tables\Columns\TextColumn;
use Sylament\Tables\Table;
use Sylament\Tables\Tests\Fixtures\Concerns\TableHost;
use Symfony\UX\LiveComponent\Attribute\PostHydrate;
use Symfony\UX\TwigComponent\Attribute\PostMount;

/**
 * @param list<Column> $columns
 */
function columnManagerHost(array $columns, ?Closure $configure = null): TableHost
{
    return new TableHost(function (Table $table) use ($columns, $configure): Table {
        $table->columns($columns);

        return $configure instanceof Closure ? $configure($table) : $table;
    });
}

it('drops the toggle memo and the table visible-column cache when the column set is replaced', function (): void {
    $host = new TableHost(fn (Table $table): Table => $table->columns([
        TextColumn::make('title'),
        TextColumn::make('plays')->toggleable(),
    ]));
    $host->tableColumns = [
        ['type' => 'column', 'name' => 'plays', 'isToggled' => true, 'isToggledHiddenByDefault' => false],
    ];

    expect($host->isTableColumnToggledHidden('plays'))->toBeFalse()
        ->and(array_keys($host->getTable()->getVisibleColumns()))->toBe(['title', 'plays']);

    new ReflectionMethod($host, 'setTableColumns')->invoke($host, [
        ['type' => 'column', 'name' => 'plays', 'isToggled' => false, 'isToggledHiddenByDefault' => false],
    ]);

    expect($host->isTableColumnToggledHidden('plays'))->toBeTrue()
        ->and(array_keys($host->getTable()->getVisibleColumns()))->toBe(['title']);
});

it('builds the default state from the definition', function (): void {
    $host = columnManagerHost([
        TextColumn::make('title'),
        TextColumn::make('plays')->toggleable(),
        TextColumn::make('album')->toggleable(isToggledHiddenByDefault: true),
    ]);

    expect($host->getDefaultTableColumnState())->toBe([
        ['type' => 'column', 'name' => 'title', 'isToggled' => true, 'isToggledHiddenByDefault' => null],
        ['type' => 'column', 'name' => 'plays', 'isToggled' => true, 'isToggledHiddenByDefault' => false],
        ['type' => 'column', 'name' => 'album', 'isToggled' => false, 'isToggledHiddenByDefault' => true],
    ]);
});

it('keeps the stored toggle, drops a vanished column and merges a new one at its declared place', function (): void {
    $host = columnManagerHost([TextColumn::make('title')->toggleable(), TextColumn::make('plays')->toggleable()]);

    $host->applyTableColumnManager([
        ['type' => 'column', 'name' => 'gone', 'isToggled' => false, 'isToggledHiddenByDefault' => false],
        ['type' => 'column', 'name' => 'title', 'isToggled' => false, 'isToggledHiddenByDefault' => false],
    ]);

    expect(array_column($host->tableColumns, 'isToggled', 'name'))->toBe(['title' => false, 'plays' => true])
        ->and($host->isTableColumnToggledHidden('title'))->toBeTrue()
        ->and($host->isTableColumnToggledHidden('plays'))->toBeFalse()
        ->and($host->isTableColumnToggledHidden('gone'))->toBeTrue()
        ->and($host->tableDeferredColumns)->toBe($host->tableColumns)
        ->and(array_keys($host->getTable()->getVisibleColumns()))->toBe(['plays']);
});

it('forces a non-toggleable column on and re-seeds a column that just became toggleable', function (): void {
    $host = columnManagerHost([TextColumn::make('title'), TextColumn::make('plays')->toggleable(isToggledHiddenByDefault: true)]);

    $host->applyTableColumnManager([
        ['type' => 'column', 'name' => 'title', 'isToggled' => false, 'isToggledHiddenByDefault' => false],
        ['type' => 'column', 'name' => 'plays', 'isToggled' => true, 'isToggledHiddenByDefault' => null],
    ]);

    expect(array_column($host->tableColumns, 'isToggled', 'name'))->toBe(['title' => true, 'plays' => false])
        ->and(array_column($host->tableColumns, 'isToggledHiddenByDefault', 'name'))->toBe(['title' => null, 'plays' => true]);

    $state = $host->tableColumns;
    $state[1]['isToggled'] = true;
    $host->applyTableColumnManager($state);

    expect($host->isTableColumnToggledHidden('plays'))->toBeFalse();
});

it('keeps the stored order only while columns are reorderable, and reset restores the declared one', function (): void {
    $reorderable = columnManagerHost([TextColumn::make('title'), TextColumn::make('plays')], fn (Table $table): Table => $table->reorderableColumns());
    $static = columnManagerHost([TextColumn::make('title'), TextColumn::make('plays')]);
    $reversed = [
        ['type' => 'column', 'name' => 'plays', 'isToggled' => true, 'isToggledHiddenByDefault' => null],
        ['type' => 'column', 'name' => 'title', 'isToggled' => true, 'isToggledHiddenByDefault' => null],
    ];

    $reorderable->applyTableColumnManager($reversed);
    $static->applyTableColumnManager($reversed);

    expect(array_keys($reorderable->getTable()->getVisibleColumns()))->toBe(['plays', 'title'])
        ->and(array_keys($static->getTable()->getVisibleColumns()))->toBe(['title', 'plays'])
        ->and(array_column($reorderable->getTableColumnManagerItems(), 'name'))->toBe(['plays', 'title'])
        ->and(array_column($static->getTableColumnManagerItems(), 'name'))->toBe(['title', 'plays']);

    $reorderable->resetTableColumnManager();

    expect(array_keys($reorderable->getTable()->getVisibleColumns()))->toBe(['title', 'plays']);
});

it('applies the draft when no state is handed in, and nothing forged', function (): void {
    $host = columnManagerHost([TextColumn::make('title')->toggleable()]);

    $host->tableDeferredColumns = [['type' => 'column', 'name' => 'title', 'isToggled' => false, 'isToggledHiddenByDefault' => false]];
    $host->applyTableColumnManager();

    expect($host->isTableColumnToggledHidden('title'))->toBeTrue();

    $host->applyTableColumnManager(['not' => 'a list']);
    expect($host->tableColumns)->toBe($host->getDefaultTableColumnState());
});

it('lists the manager rows from the draft, skipping hidden columns', function (): void {
    $host = columnManagerHost([TextColumn::make('title'), TextColumn::make('secret')->hidden(), TextColumn::make('plays')->toggleable()]);
    $host->mountTableColumnManager();

    expect(array_column($host->getTableColumnManagerItems(), 'name'))->toBe(['title', 'plays'])
        ->and($host->getTableColumnManagerItems()[0])->toMatchArray(['isToggleable' => false, 'isToggled' => true])
        ->and($host->getTableColumnManagerItems()[1])->toMatchArray(['isToggleable' => true, 'isToggled' => true]);
});

it('keeps the state, and the defaults, when the manager chrome is switched off', function (): void {
    $host = columnManagerHost([
        TextColumn::make('title'),
        TextColumn::make('plays')->toggleable(),
        TextColumn::make('album')->toggleable(isToggledHiddenByDefault: true),
    ], fn (Table $table): Table => $table->columnManager(false));
    $host->mountTableColumnManager();

    expect($host->getTable()->hasColumnManager())->toBeFalse()
        ->and(array_keys($host->getTable()->getVisibleColumns()))->toBe(['title', 'plays']);
});

it('refuses a labelless column only when columns are reorderable', function (): void {
    columnManagerHost([TextColumn::make('title')->label('')])->getDefaultTableColumnState();

    expect(fn (): array => columnManagerHost([TextColumn::make('title')->label('')], fn (Table $table): Table => $table->reorderableColumns())->getDefaultTableColumnState())
        ->toThrow(LogicException::class, 'All columns must have labels when they are reorderable.');
});

it('reads the draft through the normalizer, so a forged draft yields no rows', function (): void {
    $host = columnManagerHost([TextColumn::make('title')], fn (Table $table): Table => $table->columnManager());
    $host->tableDeferredColumns = ['x'];

    expect($host->getTableColumnManagerItems())->toBe([]);
});

it('refuses a forged state whole rather than salvaging the items it can read', function (): void {
    $host = columnManagerHost([TextColumn::make('title'), TextColumn::make('plays')], fn (Table $table): Table => $table->reorderableColumns());

    $host->applyTableColumnManager([
        'second' => ['type' => 'column', 'name' => 'plays', 'isToggled' => true, 'isToggledHiddenByDefault' => null],
        'first' => ['type' => 'column', 'name' => 'title', 'isToggled' => true, 'isToggledHiddenByDefault' => null],
    ]);

    expect(array_column($host->tableColumns, 'name'))->toBe(['title', 'plays']);

    $host->applyTableColumnManager([
        ['type' => 'column', 'name' => 'plays', 'isToggled' => true, 'isToggledHiddenByDefault' => null],
        ['type' => 'column', 'name' => 'title', 'isToggled' => true, 'isToggledHiddenByDefault' => null],
        ['type' => 'column', 'isToggled' => true, 'isToggledHiddenByDefault' => null],
    ]);

    expect(array_column($host->tableColumns, 'name'))->toBe(['title', 'plays']);
});

it('appends a newly declared column to a reordered state and slots it into an unreordered one', function (): void {
    $reordered = columnManagerHost(
        [TextColumn::make('title'), TextColumn::make('plays'), TextColumn::make('position')],
        fn (Table $table): Table => $table->reorderableColumns(),
    );
    $declared = columnManagerHost(
        [TextColumn::make('title'), TextColumn::make('plays'), TextColumn::make('position')],
        fn (Table $table): Table => $table->reorderableColumns(),
    );

    $reordered->applyTableColumnManager([
        ['type' => 'column', 'name' => 'position', 'isToggled' => true, 'isToggledHiddenByDefault' => null],
        ['type' => 'column', 'name' => 'title', 'isToggled' => true, 'isToggledHiddenByDefault' => null],
    ]);
    $declared->applyTableColumnManager([
        ['type' => 'column', 'name' => 'title', 'isToggled' => true, 'isToggledHiddenByDefault' => null],
        ['type' => 'column', 'name' => 'position', 'isToggled' => true, 'isToggledHiddenByDefault' => null],
    ]);

    expect(array_column($reordered->tableColumns, 'name'))->toBe(['position', 'title', 'plays'])
        ->and(array_keys($reordered->getTable()->getColumns()))->toBe(['position', 'title', 'plays'])
        ->and(array_column($declared->tableColumns, 'name'))->toBe(['title', 'plays', 'position'])
        ->and(array_keys($declared->getTable()->getColumns()))->toBe(['title', 'plays', 'position']);
});

it('reads a toggle that is not a boolean as on', function (): void {
    $host = columnManagerHost([TextColumn::make('title')->toggleable(isToggledHiddenByDefault: true)]);

    $host->applyTableColumnManager([['type' => 'column', 'name' => 'title', 'isToggled' => 'on', 'isToggledHiddenByDefault' => true]]);

    expect($host->isTableColumnToggledHidden('title'))->toBeFalse();
});

it('lists no row for a stored entry that is not a column, and never matches one on its name alone', function (): void {
    $host = columnManagerHost([TextColumn::make('title')->toggleable()]);

    $host->tableDeferredColumns = [
        ['type' => TableHost::TABLE_COLUMN_MANAGER_GROUP_TYPE, 'name' => 'title', 'isToggled' => true, 'isToggledHiddenByDefault' => null],
        ['type' => 'column', 'name' => 'title', 'isToggled' => true, 'isToggledHiddenByDefault' => null],
    ];

    expect($host->getTableColumnManagerItems())->toHaveCount(1);

    $host->applyTableColumnManager([
        ['type' => TableHost::TABLE_COLUMN_MANAGER_GROUP_TYPE, 'name' => 'title', 'isToggled' => false, 'isToggledHiddenByDefault' => false],
    ]);

    expect($host->isTableColumnToggledHidden('title'))->toBeFalse();
});

it('restores the declared order on reset however many applies reordered it', function (): void {
    $host = columnManagerHost(
        [TextColumn::make('title'), TextColumn::make('plays'), TextColumn::make('position')],
        fn (Table $table): Table => $table->reorderableColumns(),
    );
    $reversed = array_reverse($host->getDefaultTableColumnState());

    $host->applyTableColumnManager($reversed);
    $host->applyTableColumnManager($reversed);
    $host->resetTableColumnManager();

    expect(array_column($host->tableColumns, 'name'))->toBe(['title', 'plays', 'position'])
        ->and(array_keys($host->getTable()->getColumns()))->toBe(['title', 'plays', 'position']);
});

it('re-applies a stored order on a round trip that is not an apply', function (): void {
    $host = columnManagerHost([TextColumn::make('title'), TextColumn::make('plays')], fn (Table $table): Table => $table->reorderableColumns());
    $host->tableColumns = array_reverse($host->getDefaultTableColumnState());
    $host->tableDeferredColumns = $host->tableColumns;

    $host->afterTableColumnManagerHydrated();

    expect(array_keys($host->getTable()->getColumns()))->toBe(['plays', 'title'])
        ->and(array_column($host->tableColumns, 'name'))->toBe(['plays', 'title']);
});

it('re-derives the draft against the definition, so a column declared since carries a row again', function (): void {
    $host = columnManagerHost([TextColumn::make('title')->toggleable(), TextColumn::make('plays')->toggleable()]);
    $host->tableDeferredColumns = [
        ['type' => 'column', 'name' => 'title', 'isToggled' => true, 'isToggledHiddenByDefault' => false],
    ];

    $host->afterTableColumnManagerHydrated();

    expect(array_column($host->getTableColumnManagerItems(), 'name'))->toBe(['title', 'plays']);
});

it('drops a stored column the definition no longer declares from a reordered state', function (): void {
    $host = columnManagerHost([TextColumn::make('title'), TextColumn::make('plays')], fn (Table $table): Table => $table->reorderableColumns());

    $host->applyTableColumnManager([
        ['type' => 'column', 'name' => 'plays', 'isToggled' => true, 'isToggledHiddenByDefault' => null],
        ['type' => 'column', 'name' => 'gone', 'isToggled' => true, 'isToggledHiddenByDefault' => null],
        ['type' => 'column', 'name' => 'title', 'isToggled' => true, 'isToggledHiddenByDefault' => null],
    ]);

    expect(array_column($host->tableColumns, 'name'))->toBe(['plays', 'title']);
});

it('seeds a state for a table that is only reorderable, and none for one with nothing to edit', function (): void {
    $reorderable = columnManagerHost([TextColumn::make('title'), TextColumn::make('plays')], fn (Table $table): Table => $table->reorderableColumns());
    $plain = columnManagerHost([TextColumn::make('title'), TextColumn::make('plays')]);

    $reorderable->mountTableColumnManager();
    $plain->mountTableColumnManager();
    $plain->afterTableColumnManagerHydrated();

    expect(array_column($reorderable->getTableColumnManagerItems(), 'name'))->toBe(['title', 'plays'])
        ->and($plain->tableColumns)->toBe([])
        ->and($plain->tableDeferredColumns)->toBe([]);
});

it('lists no row for a manager asked for over columns it can neither toggle nor move', function (): void {
    $host = columnManagerHost([TextColumn::make('title')], fn (Table $table): Table => $table->columnManager());

    $host->mountTableColumnManager();

    expect($host->getTable()->hasColumnManager())->toBeTrue()
        ->and($host->tableColumns)->toBe([])
        ->and($host->getTableColumnManagerItems())->toBe([]);
});

it('holds both hooks at the priorities the occupied slots left free', function (): void {
    $mount = new ReflectionMethod(TableHost::class, 'mountTableColumnManager')->getAttributes(PostMount::class);
    $hydrate = new ReflectionMethod(TableHost::class, 'afterTableColumnManagerHydrated')->getAttributes(PostHydrate::class);

    expect($mount)->toHaveCount(1)
        ->and($mount[0]->newInstance()->priority)->toBe(4)
        ->and($hydrate)->toHaveCount(1)
        ->and($hydrate[0]->newInstance()->priority)->toBe(-4);
});
