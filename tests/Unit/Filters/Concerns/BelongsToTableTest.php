<?php

declare(strict_types=1);

use Sylament\Tables\Filters\BaseFilter;
use Sylament\Tables\Table;
use Sylament\Tables\Tests\Fixtures\Concerns\BareTableHost;
use Sylament\Tables\Tests\Fixtures\Concerns\FilterStateHost;

use function Sylament\Tables\Tests\attachFilter;

it('resolves the table and its live component', function (): void {
    $host = new FilterStateHost();
    $table = Table::make($host);
    $filter = BaseFilter::make('status')->table($table);

    expect($filter->getTable())->toBe($table)
        ->and($filter->getLiveComponent())->toBe($host);
});

it('reads the applied and form state bags by filter name', function (): void {
    $filter = attachFilter(
        BaseFilter::make('status'),
        state: ['status' => ['value' => 'published']],
        formState: ['status' => ['value' => 'draft']],
    );

    expect($filter->getState())->toBe(['value' => 'published'])
        ->and($filter->getFormState())->toBe(['value' => 'draft']);
});

it('reads an absent filter as an empty bag', function (): void {
    $filter = attachFilter(BaseFilter::make('status'));

    expect($filter->getState())->toBe([])
        ->and($filter->getFormState())->toBe([]);
});

it('refuses a host that provides no filter state', function (): void {
    $filter = BaseFilter::make('status')->table(Table::make(new BareTableHost()));

    expect(fn (): array => $filter->getState())
        ->toThrow(LogicException::class, 'provides no filter state');
});
