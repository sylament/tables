<?php

declare(strict_types=1);

use Sylament\Support\Enums\FontWeight;
use Sylament\Support\Enums\TextSize;
use Sylament\Tables\Columns\TextColumn;

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
