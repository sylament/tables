<?php

declare(strict_types=1);

use Sylament\Tables\Columns\Column;
use Sylament\Tables\Columns\TextColumn;
use Sylament\Tables\Table;
use Sylament\Tables\Tests\Fixtures\Concerns\TableHost;
use Sylament\Tables\Tests\Fixtures\Records\Artist;
use Sylament\Tables\Tests\Fixtures\Records\Song;

it('requires a name on make', function (): void {
    expect(fn (): Column => Column::make())
        ->toThrow(LogicException::class, 'must have a unique name');
});

it('derives the label from the column name', function (string $name, string $label): void {
    expect(Column::make($name)->getLabel())->toBe($label);
})->with([
    ['title', 'Title'],
    ['releasedAt', 'Released at'],
    ['artist.name', 'Artist'],
]);

it('prefers an explicit label and evaluates closures with the column injected', function (): void {
    expect(Column::make('title')->label('Song')->getLabel())->toBe('Song')
        ->and(Column::make('title')->label(fn (Column $column): string => $column->getName())->getLabel())->toBe('title');
});

it('reads state from an object record by property path', function (): void {
    $column = Column::make('title')->record(new Song('Airbag', 'OK Computer'));

    expect($column->getState())->toBe('Airbag');
});

it('reads dotted state through a nested object', function (): void {
    $column = Column::make('artist.name')->record(new Song('Airbag', 'OK Computer', new Artist('Radiohead')));

    expect($column->getState())->toBe('Radiohead');
});

it('reads state from an array record', function (): void {
    $column = Column::make('title')->record(['title' => 'Let Down']);

    expect($column->getState())->toBe('Let Down');
});

it('returns null for an unreadable path instead of throwing', function (): void {
    expect(Column::make('missing')->record(new Song('Airbag', 'OK Computer'))->getState())->toBeNull()
        ->and(Column::make('missing')->record(['title' => 'x'])->getState())->toBeNull()
        ->and(Column::make('title')->getState())->toBeNull();
});

it('lets state() override the record read and injects the record', function (): void {
    $column = Column::make('title')
        ->record(['title' => 'ignored', 'album' => 'In Rainbows'])
        ->state(fn ($record): string => $record['album']);

    expect($column->getState())->toBe('In Rainbows');
});

it('binds its table and reaches the live component through it', function (): void {
    $host = new TableHost();
    $table = Table::make($host);
    $column = TextColumn::make('title')->table($table);

    expect($column->getTable())->toBe($table)
        ->and($column->getLiveComponent())->toBe($host);
});

it('injects the cell state, the live component and the bound table by name', function (): void {
    $host = new TableHost();
    $boundTable = Table::make($host);
    $record = new Song('Airbag', 'OK Computer');

    $makeColumn = fn (): TextColumn => TextColumn::make('title')->table($boundTable)->record($record);

    expect($makeColumn()->label(fn (string $state): string => $state)->getLabel())->toBe('Airbag')
        ->and($makeColumn()->state(fn ($liveComponent) => $liveComponent)->getState())->toBe($host)
        ->and($makeColumn()->state(fn (Table $table): Table => $table)->getState())->toBe($boundTable);
});

it('injects itself into a differently named parameter type-hinted as the column', function (): void {
    $column = TextColumn::make('title')->table(Table::make(new TableHost()));

    expect($column->state(fn (Column $boundColumn): Column => $boundColumn)->getState())->toBe($column);
});

it('casts an integer record key to a string and takes null back', function (): void {
    $column = Column::make('title');

    expect($column->getRecordKey())->toBeNull()
        ->and($column->recordKey(7)->getRecordKey())->toBe('7')
        ->and($column->recordKey('7')->getRecordKey())->toBe('7')
        ->and($column->recordKey(null)->getRecordKey())->toBeNull();
});

it('caches state per record key and re-reads for a new key', function (): void {
    $column = TextColumn::make('title');
    $column->record(['title' => 'Airbag', '__key' => '1'])->recordKey('1');

    expect($column->getState())->toBe('Airbag');

    $column->record(['title' => 'Changed', '__key' => '1']);

    expect($column->getState())->toBe('Airbag');

    $column->record(['title' => 'Let Down', '__key' => '2'])->recordKey('2');

    expect($column->getState())->toBe('Let Down');
});

it('caches a null state rather than reading the record again', function (): void {
    $column = TextColumn::make('title');
    $column->record(['title' => null, '__key' => '1'])->recordKey('1');

    expect($column->getState())->toBeNull();

    $column->record(['title' => 'Airbag', '__key' => '1']);

    expect($column->getState())->toBeNull();
});

it('reads fresh every time without a record key', function (): void {
    $column = TextColumn::make('title');
    $column->record(['title' => 'Airbag']);

    expect($column->getState())->toBe('Airbag');

    $column->record(['title' => 'Changed']);

    expect($column->getState())->toBe('Changed');
});

it('clears the cached state', function (): void {
    $column = TextColumn::make('title');
    $column->record(['title' => 'Airbag'])->recordKey('1');
    $column->getState();
    $column->record(['title' => 'Changed']);
    $column->clearCachedState();

    expect($column->getState())->toBe('Changed');
});

it('is not sortable by default', function (): void {
    expect(TextColumn::make('title')->isSortable())->toBeFalse();
});

it('is sortable through the flag, a closure, and the column-list arm', function (): void {
    expect(TextColumn::make('title')->sortable()->isSortable())->toBeTrue()
        ->and(TextColumn::make('title')->sortable(false)->isSortable())->toBeFalse()
        ->and(TextColumn::make('title')->sortable(fn (): bool => true)->isSortable())->toBeTrue()
        ->and(TextColumn::make('title')->sortable(['title', 'subtitle'])->isSortable())->toBeTrue();
});

it('is not searchable by default', function (): void {
    $column = TextColumn::make('title');

    expect($column->isSearchable())->toBeFalse()
        ->and($column->isGloballySearchable())->toBeFalse()
        ->and($column->isIndividuallySearchable())->toBeFalse();
});

it('is globally searchable by default once marked searchable', function (): void {
    $column = TextColumn::make('title')->searchable();

    expect($column->isSearchable())->toBeTrue()
        ->and($column->isGloballySearchable())->toBeTrue()
        ->and($column->isIndividuallySearchable())->toBeFalse();
});

it('evaluates a searchable closure as the condition, not as a search column', function (): void {
    expect(TextColumn::make('title')->searchable(fn (): bool => false)->isSearchable())->toBeFalse()
        ->and(TextColumn::make('title')->searchable(fn (): bool => true)->isGloballySearchable())->toBeTrue();
});

it('takes search-column strings while staying searchable', function (): void {
    expect(TextColumn::make('title')->searchable('title')->isSearchable())->toBeTrue()
        ->and(TextColumn::make('title')->searchable(['title', 'subtitle'])->isSearchable())->toBeTrue();
});

it('scopes searchability with the isGlobal and isIndividual flags', function (): void {
    $individual = TextColumn::make('title')->searchable(isIndividual: true, isGlobal: false);

    expect($individual->isSearchable())->toBeTrue()
        ->and($individual->isGloballySearchable())->toBeFalse()
        ->and($individual->isIndividuallySearchable())->toBeTrue();
});
