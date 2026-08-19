<?php

declare(strict_types=1);

use Sylament\Tables\Filters\BaseFilter;

it('defaults to null, to true when default() is bare, and evaluates closures', function (): void {
    expect(BaseFilter::make('status')->getDefaultState())->toBeNull()
        ->and(BaseFilter::make('status')->default()->getDefaultState())->toBeTrue()
        ->and(BaseFilter::make('status')->default('published')->getDefaultState())->toBe('published')
        ->and(BaseFilter::make('status')->default(fn (): string => 'draft')->getDefaultState())->toBe('draft');
});
