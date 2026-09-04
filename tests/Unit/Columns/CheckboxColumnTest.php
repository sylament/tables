<?php

declare(strict_types=1);

use Sylament\Tables\Columns\CheckboxColumn;
use Sylament\Tables\Columns\Contracts\Editable;

it('is editable, click-disabled and boolean-typed out of the box', function (): void {
    $column = CheckboxColumn::make('isFeatured');

    expect($column)->toBeInstanceOf(Editable::class)
        ->and($column->isClickDisabled())->toBeTrue()
        ->and($column->getValidationConstraints())->toHaveCount(1);
});

it('merges extra input attributes onto the control', function (): void {
    $column = CheckboxColumn::make('isFeatured')
        ->extraInputAttributes(['data-role' => 'flag'])
        ->extraInputAttributes(fn (): array => ['data-row' => 'one'], merge: true);

    expect($column->getExtraInputAttributes())->toMatchArray(['data-role' => 'flag', 'data-row' => 'one']);
});
