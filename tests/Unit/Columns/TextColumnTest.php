<?php

declare(strict_types=1);

use Sylament\Support\Enums\FontWeight;
use Sylament\Support\Enums\TextSize;
use Sylament\Tables\Columns\TextColumn;
use Sylament\Tables\Table;
use Sylament\Tables\Tests\Fixtures\Concerns\TableHost;

it('passes state through unformatted by default', function (): void {
    expect(TextColumn::make('title')->record(['title' => 'Airbag'])->getFormattedState())->toBe('Airbag');
});

it('formats state with the state and record injected', function (): void {
    $column = TextColumn::make('status')
        ->record(['status' => 'draft', 'title' => 'Airbag'])
        ->formatStateUsing(fn (string $state, $record): string => mb_strtoupper($state) . ' — ' . $record['title']);

    expect($column->getFormattedState())->toBe('DRAFT — Airbag');
});

it('accepts a literal for formatStateUsing', function (): void {
    expect(TextColumn::make('status')->record(['status' => 'draft'])->formatStateUsing('fixed')->getFormattedState())->toBe('fixed');
});

it('defaults the size to small and aliases base to medium', function (): void {
    expect(TextColumn::make('title')->getSize(null))->toBe(TextSize::Small)
        ->and(TextColumn::make('title')->size('base')->getSize(null))->toBe(TextSize::Medium)
        ->and(TextColumn::make('title')->size(TextSize::Large)->getSize(null))->toBe(TextSize::Large)
        ->and(TextColumn::make('title')->size('sy-custom')->getSize(null))->toBe('sy-custom');
});

it('emits a size class fragment for an enum and passes a custom string through', function (): void {
    expect(TextColumn::make('title')->getSizeClass(null))->toBe('sy-size-sm')
        ->and(TextColumn::make('title')->size('sy-custom')->getSizeClass(null))->toBe('sy-custom');
});

it('is not a badge by default and evaluates a badge condition', function (): void {
    expect(TextColumn::make('title')->isBadge())->toBeFalse()
        ->and(TextColumn::make('title')->badge()->isBadge())->toBeTrue()
        ->and(TextColumn::make('title')->badge(fn (): bool => false)->isBadge())->toBeFalse();
});

it('returns one item for a scalar state', function (): void {
    expect(TextColumn::make('title')->record(['title' => 'Airbag'])->getStateItems())
        ->toBe([['state' => 'Airbag', 'content' => 'Airbag']]);
});

/*
 * The collapse boundary, pinned with a value the join would change: one entry stays raw,
 * so `state` is still the `int` a colour or icon closure would be handed, where collapsing
 * it — what `count($values) >= 1` would do — formats it to the string `content` already
 * holds and the two stop being distinguishable.
 */
it('leaves a single value raw rather than collapsing it', function (): void {
    expect(TextColumn::make('plays')->record(['plays' => ['n' => 5]])->getStateItems())
        ->toBe([['state' => 5, 'content' => '5']]);
});

it('collapses a multi-value state into one comma joined item', function (): void {
    expect(TextColumn::make('tags')->record(['tags' => ['symfony', 'forms']])->getStateItems())
        ->toBe([['state' => 'symfony, forms', 'content' => 'symfony, forms']]);
});

it('keeps a multi-value state per item when badged', function (): void {
    expect(TextColumn::make('tags')->badge()->record(['tags' => ['symfony', 'forms']])->getStateItems())
        ->toBe([
            ['state' => 'symfony', 'content' => 'symfony'],
            ['state' => 'forms', 'content' => 'forms'],
        ]);
});

it('collapses a traversable state the same as an array', function (): void {
    expect(TextColumn::make('tags')->state(fn (): ArrayIterator => new ArrayIterator(['symfony', 'forms']))->getStateItems())
        ->toBe([['state' => 'symfony, forms', 'content' => 'symfony, forms']]);
});

it('keeps a traversable state per item when badged', function (): void {
    expect(TextColumn::make('tags')->badge()->state(fn (): ArrayIterator => new ArrayIterator(['symfony', 'forms']))->getStateItems())
        ->toBe([
            ['state' => 'symfony', 'content' => 'symfony'],
            ['state' => 'forms', 'content' => 'forms'],
        ]);
});

it('formats each item before collapsing', function (): void {
    expect(TextColumn::make('tags')
        ->record(['tags' => ['symfony', 'forms']])
        ->formatStateUsing(fn (string $state): string => "[{$state}]")
        ->getStateItems())
        ->toBe([['state' => '[symfony], [forms]', 'content' => '[symfony], [forms]']]);
});

it('returns no items for a blank state', function (): void {
    expect(TextColumn::make('note')->record(['note' => null])->getStateItems())->toBe([]);
});

it('binds the item state to a per item colour closure when badged', function (): void {
    $column = TextColumn::make('tags')
        ->badge()
        ->record(['tags' => ['symfony', 'forms']])
        ->color(fn ($state): string => $state === 'symfony' ? 'success' : 'gray');

    $items = $column->getStateItems();

    expect($column->getColor($items[0]['state']))->toBe('success')
        ->and($column->getColor($items[1]['state']))->toBe('gray');
});

it('gives a weight closure the column state, not an item', function (): void {
    $column = TextColumn::make('tags')
        ->badge()
        ->record(['tags' => ['symfony', 'forms']])
        ->weight(fn ($state): string => is_array($state) ? 'bold' : 'normal');

    expect($column->getWeight())->toBe(FontWeight::Bold);
});

it('turns line breaks on with bullets and reads the list flags back', function (): void {
    expect(TextColumn::make('tags')->isBulleted())->toBeFalse()
        ->and(TextColumn::make('tags')->isListWithLineBreaks())->toBeFalse()
        ->and(TextColumn::make('tags')->bulleted()->isBulleted())->toBeTrue()
        ->and(TextColumn::make('tags')->bulleted()->isListWithLineBreaks())->toBeTrue()
        ->and(TextColumn::make('tags')->listWithLineBreaks()->isListWithLineBreaks())->toBeTrue()
        ->and(TextColumn::make('tags')->bulleted(fn (): bool => false)->isBulleted())->toBeFalse()
        ->and(TextColumn::make('tags')->getListLimit())->toBeNull()
        ->and(TextColumn::make('tags')->limitList()->getListLimit())->toBe(3)
        ->and(TextColumn::make('tags')->limitList(fn (): int => 2)->getListLimit())->toBe(2)
        ->and(TextColumn::make('tags')->isLimitedListExpandable())->toBeFalse()
        ->and(TextColumn::make('tags')->expandableLimitedList()->isLimitedListExpandable())->toBeTrue();
});

it('counts the values past the list limit', function (): void {
    $record = ['tags' => ['a', 'b', 'c', 'd', 'e']];

    expect(TextColumn::make('tags')->record($record)->getStateOverListLimitCount())->toBe(0)
        ->and(TextColumn::make('tags')->record($record)->limitList()->getStateOverListLimitCount())->toBe(2)
        ->and(TextColumn::make('tags')->record($record)->limitList(5)->getStateOverListLimitCount())->toBe(0)
        ->and(TextColumn::make('tags')->record($record)->limitList(0)->getStateOverListLimitCount())->toBe(0)
        ->and(TextColumn::make('tags')->record(['tags' => null])->limitList()->getStateOverListLimitCount())->toBe(0);
});

it('slices a limited list before joining it', function (): void {
    expect(TextColumn::make('tags')->record(['tags' => ['a', 'b', 'c', 'd', 'e']])->limitList()->getStateItems())
        ->toBe([['state' => 'a, b, c', 'content' => 'a, b, c']]);
});

it('keeps a limited list as items when it has line breaks', function (): void {
    $items = TextColumn::make('tags')->record(['tags' => ['a', 'b', 'c', 'd', 'e']])->listWithLineBreaks()->limitList()->getStateItems();

    expect(array_column($items, 'state'))->toBe(['a', 'b', 'c']);
});

/*
 * The one shape that keeps the over-limit values in the markup: the client toggles them,
 * so the server has to render them. Every other combination slices them away.
 */
it('keeps every value when an expandable list has line breaks', function (): void {
    $items = TextColumn::make('tags')->record(['tags' => ['a', 'b', 'c', 'd', 'e']])
        ->listWithLineBreaks()
        ->expandableLimitedList()
        ->limitList()
        ->getStateItems();

    expect(array_column($items, 'state'))->toBe(['a', 'b', 'c', 'd', 'e']);
});

it('slices an expandable list that has no line breaks', function (): void {
    $items = TextColumn::make('tags')->record(['tags' => ['a', 'b', 'c', 'd', 'e']])
        ->expandableLimitedList()
        ->limitList()
        ->bulleted(false)
        ->getStateItems();

    expect($items)->toBe([['state' => 'a, b, c', 'content' => 'a, b, c']]);
});

it('leaves a multi-value state as items once bullets force line breaks', function (): void {
    $items = TextColumn::make('tags')->record(['tags' => ['a', 'b']])->bulleted()->getStateItems();

    expect(array_column($items, 'state'))->toBe(['a', 'b']);
});

it('numbers rows from the loop, one-based by default', function (): void {
    $column = TextColumn::make('index')->table(Table::make(new TableHost()))->rowIndex();

    expect($column->rowLoop(['index' => 2, 'index0' => 1])->getState())->toBe('2')
        ->and(TextColumn::make('index')->table(Table::make(new TableHost()))->rowIndex(isFromZero: true)->rowLoop(['index' => 2, 'index0' => 1])->getState())->toBe('1');
});

it('offsets the row index by the page when the host paginates', function (): void {
    $host = new TableHost(fn (Table $table): Table => $table->paginated([10]));
    $host->tableRecordsPerPage = '10';
    $host->tablePage = 3;

    expect(TextColumn::make('index')->table($host->getTable())->rowIndex()->rowLoop(['index' => 2, 'index0' => 1])->getState())->toBe('22')
        ->and(TextColumn::make('index')->table($host->getTable())->rowIndex(isFromZero: true)->rowLoop(['index' => 2, 'index0' => 1])->getState())->toBe('21');
});

it('leaves the row index unoffset when the host reports a non-numeric page size', function (): void {
    $host = new TableHost(fn (Table $table): Table => $table->paginated([10, 'all']));
    $host->tableRecordsPerPage = 'all';
    $host->tablePage = 3;

    expect(TextColumn::make('index')->table($host->getTable())->rowIndex()->rowLoop(['index' => 2, 'index0' => 1])->getState())->toBe('2');
});

it('refuses a row index with no row loop behind it', function (): void {
    expect(fn (): mixed => TextColumn::make('index')->table(Table::make(new TableHost()))->rowIndex()->rowLoop(['first' => true])->getState())
        ->toThrow(LogicException::class, 'rowIndex() on column [index]');
});

it('copies nothing by default and carries the copy settings it is given', function (): void {
    expect(TextColumn::make('title')->hasCopyable())->toBeFalse()
        ->and(TextColumn::make('title')->isCopyable('Airbag'))->toBeFalse()
        ->and(TextColumn::make('title')->copyable()->hasCopyable())->toBeTrue()
        ->and(TextColumn::make('title')->copyable()->isCopyable('Airbag'))->toBeTrue()
        ->and(TextColumn::make('title')->copyable()->getCopyMessage('Airbag'))->toBe('Copied')
        ->and(TextColumn::make('title')->copyable()->getCopyMessageDuration('Airbag'))->toBe(2000)
        ->and(TextColumn::make('title')->copyable()->copyableState(fn (string $state): string => mb_strtoupper($state))->getCopyableState('Airbag'))->toBe('AIRBAG');
});
