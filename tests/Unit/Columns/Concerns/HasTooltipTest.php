<?php

declare(strict_types=1);

use Sylament\Tables\Columns\Column;
use Twig\Markup;

it('carries no tooltip of any kind by default', function (): void {
    $column = Column::make('title');

    expect($column->getTooltip())->toBeNull()
        ->and($column->getHeaderTooltip())->toBeNull()
        ->and($column->getEmptyTooltip())->toBeNull()
        ->and($column->hasTooltip())->toBeFalse();
});

it('injects the passed state into the item tooltip closure', function (): void {
    $column = Column::make('title')->tooltip(fn (mixed $state): string => 'Copy ' . $state);

    expect($column->getTooltip('Airbag'))->toBe('Copy Airbag')
        ->and($column->getTooltip())->toBe('Copy ');
});

it('reports a tooltip as set even when it evaluates to null', function (): void {
    expect(Column::make('title')->tooltip(fn (): ?string => null)->hasTooltip())->toBeTrue()
        ->and(Column::make('title')->tooltip(null)->hasTooltip())->toBeFalse();
});

it('keeps the header and empty tooltips separate from the item one', function (): void {
    $column = Column::make('title')
        ->tooltip('item')
        ->headerTooltip('header')
        ->emptyTooltip('empty');

    expect($column->getTooltip())->toBe('item')
        ->and($column->getHeaderTooltip())->toBe('header')
        ->and($column->getEmptyTooltip())->toBe('empty');
});

it('carries markup through every tooltip getter', function (): void {
    $markup = new Markup('<b>Hi</b>', 'UTF-8');

    expect(Column::make('title')->tooltip($markup)->getTooltip())->toBe($markup)
        ->and(Column::make('title')->headerTooltip(fn (): Markup => $markup)->getHeaderTooltip())->toBe($markup)
        ->and(Column::make('title')->emptyTooltip($markup)->getEmptyTooltip())->toBe($markup);
});
