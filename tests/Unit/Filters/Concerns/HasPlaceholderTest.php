<?php

declare(strict_types=1);

use Sylament\Tables\Tests\Fixtures\Filters\OptionsFilter;

it('has no placeholder until set and evaluates the closure form', function (): void {
    expect(OptionsFilter::make('status')->getPlaceholder())->toBeNull()
        ->and(OptionsFilter::make('status')->placeholder('All')->getPlaceholder())->toBe('All')
        ->and(OptionsFilter::make('status')->placeholder(fn (): string => 'Any')->getPlaceholder())->toBe('Any');
});
