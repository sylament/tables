<?php

declare(strict_types=1);

use Sylament\Tables\Columns\Column;

it('is not inline by default', function (): void {
    expect(Column::make('title')->isInline())->toBeFalse();
});

it('turns inline on by the bare call and off by a false condition', function (): void {
    expect(Column::make('title')->inline()->isInline())->toBeTrue()
        ->and(Column::make('title')->inline(false)->isInline())->toBeFalse();
});

it('evaluates an inline closure against the column', function (): void {
    expect(Column::make('title')->inline(fn (Column $column): bool => $column->getName() === 'title')->isInline())->toBeTrue()
        ->and(Column::make('album')->inline(fn (Column $column): bool => $column->getName() === 'title')->isInline())->toBeFalse();
});
