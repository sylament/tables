<?php

declare(strict_types=1);

use Sylament\Tables\Columns\Column;

it('carries no extra header attributes by default', function (): void {
    expect(Column::make('title')->getExtraHeaderAttributes())->toBe([]);
});

it('replaces the stack by default and appends when merging', function (): void {
    expect(Column::make('title')
        ->extraHeaderAttributes(['data-one' => '1'])
        ->extraHeaderAttributes(['data-two' => '2'])
        ->getExtraHeaderAttributes())->toBe(['data-two' => '2'])
        ->and(Column::make('title')
            ->extraHeaderAttributes(['data-one' => '1'])
            ->extraHeaderAttributes(['data-two' => '2'], merge: true)
            ->getExtraHeaderAttributes())->toBe(['data-two' => '2', 'data-one' => '1']);
});

it('evaluates a closure entry against the column', function (): void {
    expect(Column::make('title')
        ->extraHeaderAttributes(fn (Column $column): array => ['data-name' => $column->getName()])
        ->getExtraHeaderAttributes())->toBe(['data-name' => 'title']);
});

it('keeps the header stack apart from the cell one', function (): void {
    $column = Column::make('title')
        ->extraHeaderAttributes(['data-where' => 'header'])
        ->extraCellAttributes(['data-where' => 'cell']);

    expect($column->getExtraHeaderAttributes())->toBe(['data-where' => 'header'])
        ->and($column->getExtraCellAttributes())->toBe(['data-where' => 'cell']);
});

it('does not ship the bag getter', function (): void {
    expect(method_exists(Column::class, 'getExtraHeaderAttributeBag'))->toBeFalse();
});
