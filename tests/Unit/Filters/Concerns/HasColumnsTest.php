<?php

declare(strict_types=1);

use Sylament\Tables\Filters\BaseFilter;

it('carries no columns until set and defaults the setter to two', function (): void {
    expect(BaseFilter::make('status')->getColumns())->toBeNull()
        ->and(BaseFilter::make('status')->columns()->getColumns())->toBe(2)
        ->and(BaseFilter::make('status')->columns(['md' => 3])->getColumns())->toBe(['md' => 3]);
});
