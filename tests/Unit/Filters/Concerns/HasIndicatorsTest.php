<?php

declare(strict_types=1);

use Sylament\Tables\Filters\BaseFilter;
use Sylament\Tables\Filters\Indicator;

use function Sylament\Tables\Tests\attachFilter;

it('has no indicators without a callback and drops a blank result', function (): void {
    $filter = attachFilter(BaseFilter::make('status'), state: ['status' => ['value' => 'published']]);

    expect($filter->getIndicators())->toBe([])
        ->and($filter->indicateUsing(fn (): array => [])->getIndicators())->toBe([])
        ->and($filter->indicateUsing(fn (): ?string => null)->getIndicators())->toBe([]);
});

it('wraps a scalar result and keys a string key onto removeField', function (): void {
    $filter = attachFilter(BaseFilter::make('status'), state: ['status' => ['value' => 'published']]);

    $wrapped = $filter->indicateUsing(fn (): string => 'Published')->getIndicators();
    expect($wrapped)->toHaveCount(1)
        ->and($wrapped[0])->toBeInstanceOf(Indicator::class)
        ->and($wrapped[0]->getLabel())->toBe('Published')
        ->and($wrapped[0]->getRemoveField())->toBeNull();

    $keyed = $filter->indicateUsing(fn (): array => ['value' => 'Published'])->getIndicators();
    expect($keyed['value']->getRemoveField())->toBe('value');

    $verbatim = $filter->indicateUsing(fn (): array => [Indicator::make('Kept')->color('danger')])->getIndicators();
    expect($verbatim[0]->getColor())->toBe('danger');
});

it('hands the state to the callback as both data and state', function (): void {
    $filter = attachFilter(BaseFilter::make('status'), state: ['status' => ['value' => 'published']]);

    expect($filter->indicateUsing(fn (array $data, array $state): string => $data['value'] . ':' . $state['value'])->getIndicators()[0]->getLabel())
        ->toBe('published:published');
});

it('falls back to the label for the single indicator', function (): void {
    $filter = attachFilter(BaseFilter::make('status'), state: ['status' => ['value' => 'published']]);

    expect($filter->getIndicator())->toBe('Status')
        ->and($filter->indicator('Published')->getIndicator())->toBe('Published')
        ->and($filter->indicator(fn (array $state): string => (string) $state['value'])->getIndicator())->toBe('published');
});
