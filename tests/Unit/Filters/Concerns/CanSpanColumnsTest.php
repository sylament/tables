<?php

declare(strict_types=1);

use Sylament\Tables\Filters\BaseFilter;

it('forwards the span and start raw, closures included', function (): void {
    $span = fn (): int => 2;

    expect(BaseFilter::make('status')->getColumnSpan())->toBe(1)
        ->and(BaseFilter::make('status')->getColumnStart())->toBeNull()
        ->and(BaseFilter::make('status')->columnSpan($span)->getColumnSpan())->toBeInstanceOf(Closure::class)
        ->and(BaseFilter::make('status')->columnSpanFull()->getColumnSpan())->toBe(['default' => 'full'])
        ->and(BaseFilter::make('status')->columnStart(['md' => 2])->getColumnStart())->toBe(['md' => 2]);
});
