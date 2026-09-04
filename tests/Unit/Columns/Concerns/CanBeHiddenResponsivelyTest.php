<?php

declare(strict_types=1);

use Sylament\Tables\Columns\Column;

it('names no breakpoint by default', function (): void {
    $column = Column::make('title');

    expect($column->getHiddenFrom())->toBeNull()
        ->and($column->getVisibleFrom())->toBeNull();
});

it('stores each breakpoint independently and evaluates a closure', function (): void {
    expect(Column::make('title')->hiddenFrom('md')->getHiddenFrom())->toBe('md')
        ->and(Column::make('title')->hiddenFrom('md')->getVisibleFrom())->toBeNull()
        ->and(Column::make('title')->visibleFrom(fn (Column $column): string => $column->getName() === 'title' ? 'lg' : 'sm')->getVisibleFrom())->toBe('lg');
});

it('does not ship the cache guards the cached cell bag would need', function (): void {
    expect(method_exists(Column::class, 'hasDynamicHiddenFrom'))->toBeFalse()
        ->and(method_exists(Column::class, 'hasDynamicVisibleFrom'))->toBeFalse();
});
