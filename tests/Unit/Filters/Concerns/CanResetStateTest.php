<?php

declare(strict_types=1);

use Sylament\Tables\Filters\BaseFilter;

it('resets to an empty map by default and evaluates the closure form', function (): void {
    expect(BaseFilter::make('status')->getResetState())->toBe([])
        ->and(BaseFilter::make('status')->resetState(['value' => null])->getResetState())->toBe(['value' => null])
        ->and(BaseFilter::make('status')->resetState(fn (): array => ['value' => []])->getResetState())->toBe(['value' => []]);
});
