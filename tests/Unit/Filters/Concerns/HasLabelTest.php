<?php

declare(strict_types=1);

use Sylament\Tables\Filters\BaseFilter;

it('derives the label from the name', function (string $name, string $label): void {
    expect(BaseFilter::make($name)->getLabel())->toBe($label);
})->with([
    ['status', 'Status'],
    ['is_featured', 'Is featured'],
    ['isFeatured', 'Is featured'],
    ['author.name', 'Author'],
]);

it('prefers an explicit label, closure included', function (): void {
    expect(BaseFilter::make('status')->label('Publication status')->getLabel())->toBe('Publication status')
        ->and(BaseFilter::make('status')->label(fn ($filter): string => $filter->getName())->getLabel())->toBe('status');
});
