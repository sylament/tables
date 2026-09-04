<?php

declare(strict_types=1);

use Sylament\Tables\Columns\Column;
use Sylament\Tables\Columns\Contracts\Editable;
use Sylament\Tables\Columns\ToggleColumn;

it('is editable, click-disabled and boolean-typed out of the box', function (): void {
    $column = ToggleColumn::make('isFeatured');

    expect($column)->toBeInstanceOf(Editable::class)
        ->and($column)->toBeInstanceOf(Column::class)
        ->and($column->isClickDisabled())->toBeTrue()
        ->and($column->isDisabled())->toBeFalse()
        ->and($column->getValidationConstraints())->toHaveCount(1);
});

it('carries the toggle colours and icons forms declares', function (): void {
    $column = ToggleColumn::make('isFeatured')
        ->onColor('success')
        ->offColor(fn (): string => 'danger')
        ->onIcon('heroicons:check-circle')
        ->offIcon('heroicons:x-circle');

    expect($column->getOnColor())->toBe('success')
        ->and($column->getOffColor())->toBe('danger')
        ->and($column->getOnIcon())->toBe('heroicons:check-circle')
        ->and($column->hasOnIcon())->toBeTrue()
        ->and($column->getOffIcon())->toBe('heroicons:x-circle')
        ->and($column->hasOffIcon())->toBeTrue();
});

it('has no toggle colours or icons unless they are set', function (): void {
    $column = ToggleColumn::make('isFeatured');

    expect($column->getOnColor())->toBeNull()
        ->and($column->getOffColor())->toBeNull()
        ->and($column->hasOnIcon())->toBeFalse()
        ->and($column->hasOffIcon())->toBeFalse();
});
