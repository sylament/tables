<?php

declare(strict_types=1);

use Sylament\Tables\Columns\Column;

it('carries no extra cell attributes by default', function (): void {
    expect(Column::make('title')->getExtraCellAttributes())->toBe([]);
});

it('replaces the stack by default and appends when merging', function (): void {
    expect(Column::make('title')
        ->extraCellAttributes(['data-one' => '1'])
        ->extraCellAttributes(['data-two' => '2'])
        ->getExtraCellAttributes())->toBe(['data-two' => '2'])
        ->and(Column::make('title')
            ->extraCellAttributes(['data-one' => '1'])
            ->extraCellAttributes(['data-two' => '2'], merge: true)
            ->getExtraCellAttributes())->toBe(['data-two' => '2', 'data-one' => '1']);
});

it('evaluates a closure entry and lets the earlier registration win a repeated key', function (): void {
    expect(Column::make('title')
        ->extraCellAttributes(['data-name' => 'first'])
        ->extraCellAttributes(fn (Column $column): array => ['data-name' => $column->getName()], merge: true)
        ->getExtraCellAttributes())->toBe(['data-name' => 'first']);
});

it('appends class tokens rather than replacing them', function (): void {
    expect(Column::make('title')
        ->extraCellAttributes(['class' => 'sy-one'])
        ->extraCellAttributes(['class' => 'sy-two'], merge: true)
        ->getExtraCellAttributes())->toBe(['class' => ['sy-two', 'sy-one']]);
});

it('does not ship the cache guard or the bag getter', function (): void {
    expect(method_exists(Column::class, 'hasDynamicExtraCellAttributes'))->toBeFalse()
        ->and(method_exists(Column::class, 'getExtraCellAttributeBag'))->toBeFalse();
});
