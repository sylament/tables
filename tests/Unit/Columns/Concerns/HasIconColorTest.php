<?php

declare(strict_types=1);

use Sylament\Tables\Columns\TextColumn;
use Sylament\Tables\Tests\Fixtures\Records\Status;

it('has no icon colour by default', function (): void {
    expect(TextColumn::make('status')->getIconColor('draft'))->toBeNull();
});

it('returns a literal icon colour and evaluates a closure against the passed state', function (): void {
    expect(TextColumn::make('status')->iconColor('danger')->getIconColor('draft'))->toBe('danger')
        ->and(TextColumn::make('status')->iconColor(fn ($state): string => $state === 'draft' ? 'gray' : 'success')->getIconColor('draft'))->toBe('gray');
});

it('accepts an inline shade map', function (): void {
    $palette = [50 => 'oklch(0.99 0 0)'];

    expect(TextColumn::make('status')->iconColor($palette)->getIconColor('draft'))->toBe($palette);
});

/*
 * Unlike `color()` and `icon()`, this has no map and no state-contract fallback — Filament's
 * `HasIconColor` is the evaluation alone — so a state that carries its own colour does not
 * colour the icon with it.
 */
it('does not fall back to a state implementing the colour contract', function (): void {
    expect(TextColumn::make('status')->getIconColor(Status::Published))->toBeNull();
});
