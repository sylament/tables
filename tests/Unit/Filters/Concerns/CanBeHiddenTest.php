<?php

declare(strict_types=1);

use Sylament\Tables\Filters\BaseFilter;
use Sylament\Tables\Table;
use Sylament\Tables\Tests\Fixtures\Concerns\FilterStateHost;
use Sylament\Tables\Tests\Fixtures\Concerns\TableActionsHost;

it('is visible by default and hidden wins over visible', function (): void {
    expect(BaseFilter::make('status')->isVisible())->toBeTrue()
        ->and(BaseFilter::make('status')->hidden()->isHidden())->toBeTrue()
        ->and(BaseFilter::make('status')->hidden()->visible()->isHidden())->toBeTrue()
        ->and(BaseFilter::make('status')->visible(false)->isHidden())->toBeTrue()
        ->and(BaseFilter::make('status')->hidden(fn (): bool => true)->isVisible())->toBeFalse();
});

it('hides and shows on host class strings', function (): void {
    $table = Table::make(new FilterStateHost());

    expect(BaseFilter::make('status')->table($table)->hiddenOn(FilterStateHost::class)->isHidden())->toBeTrue()
        ->and(BaseFilter::make('status')->table($table)->hiddenOn([TableActionsHost::class])->isHidden())->toBeFalse()
        ->and(BaseFilter::make('status')->table($table)->visibleOn(FilterStateHost::class)->isVisible())->toBeTrue()
        ->and(BaseFilter::make('status')->table($table)->visibleOn(TableActionsHost::class)->isVisible())->toBeFalse();
});
