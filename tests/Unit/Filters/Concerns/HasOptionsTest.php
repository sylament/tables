<?php

declare(strict_types=1);

use Sylament\Tables\Tests\Fixtures\Filters\OptionsFilter;

it('has no options until set and evaluates the closure form', function (): void {
    expect(OptionsFilter::make('status')->getOptions())->toBe([])
        ->and(OptionsFilter::make('status')->options(['published' => 'Published'])->getOptions())->toBe(['published' => 'Published'])
        ->and(OptionsFilter::make('status')->options(fn (): array => ['draft' => 'Draft'])->getOptions())->toBe(['draft' => 'Draft']);
});
