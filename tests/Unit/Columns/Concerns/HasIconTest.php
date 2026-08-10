<?php

declare(strict_types=1);

use Sylament\Support\Enums\IconPosition;
use Sylament\Tables\Columns\TextColumn;
use Sylament\Tables\Tests\Fixtures\Records\Status;
use Twig\Markup;

it('has no icon by default and reports the before position', function (): void {
    $column = TextColumn::make('status');

    expect($column->getIcon('draft'))->toBeNull()
        ->and($column->hasIcon())->toBeFalse()
        ->and($column->getIconPosition())->toBe(IconPosition::Before);
});

it('returns a literal icon and evaluates a closure against the passed state', function (): void {
    expect(TextColumn::make('status')->icon('heroicons:x-mark')->getIcon('draft'))->toBe('heroicons:x-mark')
        ->and(TextColumn::make('status')->icon(fn ($state): string => "heroicons:{$state}")->getIcon('chevron-down'))->toBe('heroicons:chevron-down');
});

it('reports an icon once one is set, and never for the false sentinel', function (): void {
    expect(TextColumn::make('status')->icon('heroicons:x-mark')->hasIcon())->toBeTrue()
        ->and(TextColumn::make('status')->icon(fn (): string => 'heroicons:x-mark')->hasIcon())->toBeTrue()
        ->and(TextColumn::make('status')->icon(false)->hasIcon())->toBeFalse();
});

it('treats false as no icon and suppresses the state contract', function (): void {
    expect(TextColumn::make('status')->icon(false)->getIcon(Status::Published))->toBeNull();
});

it('throws when the icon evaluates to true, naming the column', function (): void {
    expect(fn (): string|BackedEnum|Markup|null => TextColumn::make('status')->icon(true)->getIcon('draft'))
        ->toThrow(LogicException::class, '[status]');
});

it('falls back to a state implementing the icon contract', function (): void {
    expect(TextColumn::make('status')->getIcon(Status::Published))->toBe('heroicons:x-mark');
});

it('resolves an icons map and accepts a string position', function (): void {
    $column = TextColumn::make('status')
        ->icons(['heroicons:x-mark' => 'published'])
        ->iconPosition('after');

    expect($column->getIcon('published'))->toBe('heroicons:x-mark')
        ->and($column->getIconPosition())->toBe(IconPosition::After);
});

it('falls back to the before position for an unknown string', function (): void {
    expect(TextColumn::make('status')->iconPosition('sideways')->getIconPosition())->toBe(IconPosition::Before);
});
