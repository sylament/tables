<?php

declare(strict_types=1);

use Sylament\Tables\Filters\Filter;
use Sylament\Tables\Filters\FilterIndicator;
use Sylament\Tables\Filters\Indicator;
use Sylament\Tables\Table;
use Sylament\Tables\Tests\Fixtures\Concerns\FilterStateHost;

/**
 * @param array<string, array<string, mixed>> $filterState
 */
function indicatorHost(Closure $configure, array $filterState = []): FilterStateHost
{
    return new FilterStateHost($configure, filterState: $filterState);
}

it('returns nothing when the indicators are hidden', function (): void {
    $host = indicatorHost(
        static fn (Table $table): Table => $table
            ->filters([Filter::make('isPublished')])
            ->hiddenFilterIndicators(),
        ['isPublished' => ['isActive' => true]],
    );

    $host->tableSearch = 'moon';

    expect($host->getTable()->getFilterIndicators())->toBe([]);
});

it('puts the global search chip first, removable through resetTableSearch', function (): void {
    $host = indicatorHost(
        static fn (Table $table): Table => $table->filters([Filter::make('isPublished')]),
        ['isPublished' => ['isActive' => true]],
    );

    $host->tableSearch = '  moon  ';

    $indicators = $host->getTable()->getFilterIndicators();

    expect($indicators)->toHaveCount(2)
        ->and($indicators[0])->toBeInstanceOf(FilterIndicator::class)
        ->and((string) $indicators[0]->indicator->getLabel())->toBe('Search: moon')
        ->and($indicators[0]->removeAction)->toBe('resetTableSearch')
        ->and($indicators[0]->removeArguments)->toBe([])
        ->and($indicators[0]->indicator->isRemovable())->toBeTrue();
});

it('omits the search chip when nothing is searched for', function (): void {
    $host = indicatorHost(
        static fn (Table $table): Table => $table->filters([Filter::make('isPublished')]),
        ['isPublished' => ['isActive' => true]],
    );

    $host->tableSearch = '   ';

    $indicators = $host->getTable()->getFilterIndicators();

    expect($indicators)->toHaveCount(1)
        ->and($indicators[0]->removeAction)->toBe('removeTableFilter');
});

it('pairs each filter indicator with its filter name and its remove field', function (): void {
    $host = indicatorHost(
        static fn (Table $table): Table => $table->filters([
            Filter::make('isPublished'),
            Filter::make('dates')->indicateUsing(static fn (): array => ['from' => 'From: today']),
            Filter::make('concealed')->hidden(),
        ]),
        ['isPublished' => ['isActive' => true]],
    );

    $indicators = $host->getTable()->getFilterIndicators();

    expect($indicators)->toHaveCount(2)
        ->and($indicators[0]->removeAction)->toBe('removeTableFilter')
        ->and($indicators[0]->removeArguments)->toBe(['filterName' => 'isPublished', 'field' => null])
        ->and($indicators[1]->removeArguments)->toBe(['filterName' => 'dates', 'field' => 'from'])
        ->and((string) $indicators[1]->indicator->getLabel())->toBe('From: today');
});

it('carries a non-removable indicator through unchanged', function (): void {
    $host = indicatorHost(static fn (Table $table): Table => $table->filters([
        Filter::make('locked')->indicateUsing(static fn (): array => [
            Indicator::make('Locked')->removable(false),
        ]),
    ]));

    $indicators = $host->getTable()->getFilterIndicators();

    expect($indicators)->toHaveCount(1)
        ->and($indicators[0]->indicator->isRemovable())->toBeFalse();
});
