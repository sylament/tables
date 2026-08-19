<?php

declare(strict_types=1);

use Sylament\Tables\Filters\BaseFilter;

it('keeps the name it was made with and accepts a later one', function (): void {
    expect(BaseFilter::make('status')->getName())->toBe('status')
        ->and(BaseFilter::make('status')->name('is_featured')->getName())->toBe('is_featured');
});
