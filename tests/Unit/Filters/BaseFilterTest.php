<?php

declare(strict_types=1);

use Sylament\Tables\Filters\BaseFilter;
use Sylament\Tables\Table;
use Sylament\Tables\Tests\Fixtures\Concerns\FilterStateHost;
use Sylament\Tables\Tests\Fixtures\Filters\DefaultNameFilter;

use function Sylament\Tables\Tests\attachFilter;

it('requires a name on make', function (): void {
    expect(fn (): BaseFilter => BaseFilter::make())
        ->toThrow(LogicException::class, 'must have a unique name');
});

it('falls back to the class default name', function (): void {
    expect(DefaultNameFilter::make()->getName())->toBe('defaultNamed')
        ->and(DefaultNameFilter::make('archived')->getName())->toBe('archived')
        ->and(BaseFilter::getDefaultName())->toBeNull();
});

it('runs configure on make', function (): void {
    $remove = BaseFilter::configureUsing(fn (BaseFilter $filter): BaseFilter => $filter->label('Configured'));

    expect(BaseFilter::make('status')->getLabel())->toBe('Configured');

    $remove();
});

it('injects the filter itself, the table and the live component into closures', function (): void {
    $host = new FilterStateHost();
    $table = Table::make($host);

    $filter = BaseFilter::make('status')->table($table);

    expect($filter->label(fn ($filter): string => $filter->getName())->getLabel())->toBe('status')
        ->and($filter->label(fn (Table $table): string => $table::class)->getLabel())->toBe(Table::class)
        ->and($filter->label(fn ($liveComponent): string => $liveComponent::class)->getLabel())->toBe(FilterStateHost::class);
});

it('counts the active indicators', function (): void {
    $filter = attachFilter(BaseFilter::make('status'), state: ['status' => ['value' => 'published']]);

    expect($filter->getActiveCount())->toBe(0)
        ->and($filter->indicateUsing(fn (): string => 'Published')->getActiveCount())->toBe(1);
});
