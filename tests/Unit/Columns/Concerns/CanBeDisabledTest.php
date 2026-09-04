<?php

declare(strict_types=1);

use Sylament\Tables\Columns\Column;

it('is enabled and click-enabled by default', function (): void {
    $column = Column::make('title');

    expect($column->isDisabled())->toBeFalse()
        ->and($column->isEnabled())->toBeTrue()
        ->and($column->isClickDisabled())->toBeFalse();
});

it('disables by the bare call and reports the inverse through isEnabled', function (): void {
    $column = Column::make('title')->disabled();

    expect($column->isDisabled())->toBeTrue()
        ->and($column->isEnabled())->toBeFalse();
});

it('disables the click independently of the write gate', function (): void {
    $column = Column::make('title')->disabledClick();

    expect($column->isClickDisabled())->toBeTrue()
        ->and($column->isDisabled())->toBeFalse();
});

it('evaluates both conditions as closures', function (): void {
    expect(Column::make('title')->disabled(fn (): bool => true)->isDisabled())->toBeTrue()
        ->and(Column::make('title')->disabledClick(fn (): bool => false)->isClickDisabled())->toBeFalse();
});

it('does not ship the deprecated disableClick alias', function (): void {
    expect(method_exists(Column::class, 'disableClick'))->toBeFalse();
});
