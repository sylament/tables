<?php

declare(strict_types=1);

use Sylament\Tables\Columns\TextColumn;
use Sylament\Tables\Table;
use Sylament\Tables\Tests\Fixtures\Concerns\TableHost;

/*
 * The table-tier half of per-column search — the opt-ins the search row and the
 * session lane read. The global-search settings this trait also carries stay in
 * `tests/Unit/TableTest.php`, which is where they landed with the query lane.
 */

it('persists column searches only once the table asks it to', function (): void {
    $table = Table::make(new TableHost());

    expect($table->persistsColumnSearchesInSession())->toBeFalse()
        ->and($table->persistColumnSearchesInSession()->persistsColumnSearchesInSession())->toBeTrue()
        ->and($table->persistColumnSearchesInSession(fn (): bool => false)->persistsColumnSearchesInSession())->toBeFalse();
});

it('is searchable by column only while a visible column wants an input of its own', function (): void {
    $none = Table::make(new TableHost())->columns([TextColumn::make('title')->searchable()]);
    $hidden = Table::make(new TableHost())->columns([TextColumn::make('title')->searchable(isIndividual: true)->hidden()]);
    $one = Table::make(new TableHost())->columns([
        TextColumn::make('title')->searchable(),
        TextColumn::make('plays')->searchable(isIndividual: true),
    ]);

    expect($none->isSearchableByColumn())->toBeFalse()
        ->and($hidden->isSearchableByColumn())->toBeFalse()
        ->and($one->isSearchableByColumn())->toBeTrue();
});

it('counts a column the user toggled off, because only the developer hiding it removes the input', function (): void {
    $host = new TableHost(fn (Table $table): Table => $table->columns([
        TextColumn::make('title')->searchable(isIndividual: true)->toggleable(),
    ]));
    $host->tableColumns = [['type' => 'column', 'name' => 'title', 'isToggled' => false, 'isToggledHiddenByDefault' => null]];

    $table = $host->getTable();

    expect($table->getColumn('title')?->isToggledHidden())->toBeTrue()
        ->and($table->getVisibleColumns())->toBe([])
        ->and($table->isSearchableByColumn())->toBeTrue();
});

it('hands the chips straight to the host', function (): void {
    $host = new TableHost(fn (Table $table): Table => $table->columns([TextColumn::make('title')->searchable(isIndividual: true)]));
    $host->tableColumnSearches = ['title' => 'Air'];

    $indicators = $host->getTable()->getColumnSearchIndicators();

    expect(array_keys($indicators))->toBe(['title'])
        ->and((string) $indicators['title']->getLabel())->toBe('Title: Air');
});
