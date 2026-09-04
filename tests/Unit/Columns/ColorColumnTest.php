<?php

declare(strict_types=1);

use Doctrine\Common\Collections\ArrayCollection;
use Sylament\Tables\Columns\ColorColumn;

enum ColorColumnState: string
{
    case Brand = '#4f46e5';
}

it('sanitizes each swatch colour and drops one no CSS syntax admits', function (mixed $state, ?string $color): void {
    $items = ColorColumn::make('color')->state(fn (): mixed => $state)->getStateItems();

    expect($items[0]['color'])->toBe($color)
        ->and($items[0]['state'])->toBe($state);
})->with([
    'hex' => ['#ff0000', '#ff0000'],
    'short hex' => ['#f00', '#f00'],
    'keyword' => ['rebeccapurple', 'rebeccapurple'],
    'functional' => ['rgb(255 0 0)', 'rgb(255 0 0)'],
    'declaration injection' => ['red;position:fixed', null],
    'not a colour' => ['not a colour at all', null],
    'string-backed enum' => [ColorColumnState::Brand, '#4f46e5'],
    'non-string' => [42, null],
]);

it('resolves one swatch per value in a list state', function (): void {
    $items = ColorColumn::make('palette')->state(fn (): array => ['#fff', '#000'])->getStateItems();

    expect($items)->toHaveCount(2)
        ->and($items[0]['color'])->toBe('#fff')
        ->and($items[1]['color'])->toBe('#000');
});

it('returns no state items for a blank state', function (): void {
    expect(ColorColumn::make('color')->state(fn (): null => null)->getStateItems())->toBe([]);
});

/*
 * As on [`IconColumn`](IconColumnTest.php): a loaded to-many association arrives as a Doctrine
 * `Collection`, and `wrap()` alone would file it as one value — sanitizing the collection object
 * to `null` and painting a single blank swatch where the row holds several colours.
 */
it('walks a traversable state one value at a time', function (): void {
    $items = ColorColumn::make('palette')
        ->state(fn (): ArrayCollection => new ArrayCollection(['#fff', '#000']))
        ->getStateItems();

    expect($items)->toHaveCount(2)
        ->and($items[0]['color'])->toBe('#fff')
        ->and($items[1]['color'])->toBe('#000');
});

it('carries no copy values while the column is not copyable', function (): void {
    $item = ColorColumn::make('color')->state(fn (): string => '#fff')->getStateItems()[0];

    expect($item['copyable'])->toBeFalse()
        ->and($item['copyableState'])->toBeNull()
        ->and($item['copyMessage'])->toBeNull()
        ->and($item['copyMessageDuration'])->toBeNull();
});

it('defaults a copyable swatch to the raw state, Copied and two seconds', function (): void {
    $item = ColorColumn::make('color')->copyable()->state(fn (): string => '#fff')->getStateItems()[0];

    expect($item['copyable'])->toBeTrue()
        ->and($item['copyableState'])->toBe('#fff')
        ->and($item['copyMessage'])->toBe('Copied')
        ->and($item['copyMessageDuration'])->toBe(2000);
});

it('takes the configured copyable state, message and duration per item', function (): void {
    $item = ColorColumn::make('color')
        ->copyable(fn (mixed $state): bool => $state === '#fff')
        ->copyableState(fn (mixed $state): string => 'css: ' . $state)
        ->copyMessage('Colour copied')
        ->copyMessageDuration(500)
        ->state(fn (): string => '#fff')
        ->getStateItems()[0];

    expect($item['copyableState'])->toBe('css: #fff')
        ->and($item['copyMessage'])->toBe('Colour copied')
        ->and($item['copyMessageDuration'])->toBe(500);
});

it('hands the per-item tooltip closure the value', function (): void {
    $items = ColorColumn::make('palette')
        ->state(fn (): array => ['#fff', '#000'])
        ->tooltip(fn (mixed $state): string => 'Swatch ' . $state)
        ->getStateItems();

    expect($items[0]['tooltip'])->toBe('Swatch #fff')
        ->and($items[1]['tooltip'])->toBe('Swatch #000');
});
