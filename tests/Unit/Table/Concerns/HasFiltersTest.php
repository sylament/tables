<?php

declare(strict_types=1);

use Sylament\Schemas\Components\Group;
use Sylament\Tables\Enums\FiltersLayout;
use Sylament\Tables\Enums\FiltersResetActionPosition;
use Sylament\Tables\Filters\Filter;
use Sylament\Tables\Filters\SelectFilter;
use Sylament\Tables\Table;
use Sylament\Tables\Tests\Fixtures\Concerns\FilterStateHost;

/**
 * @param array<string, array<string, mixed>> $filterState
 */
function filterConfigTable(Closure $configure, array $filterState = []): Table
{
    return new FilterStateHost($configure, filterState: $filterState)->getTable();
}

it('defaults to deferred filters in a dropdown, one column wide, resetting from the header', function (): void {
    $table = filterConfigTable(static fn (Table $table): Table => $table);

    expect($table->getFilters())->toBe([])
        ->and($table->isFilterable())->toBeFalse()
        ->and($table->isFiltered())->toBeFalse()
        ->and($table->getActiveFiltersCount())->toBe(0)
        ->and($table->hasDeferredFilters())->toBeTrue()
        ->and($table->getFiltersLayout())->toBe(FiltersLayout::Dropdown)
        ->and($table->hasFiltersDropdown())->toBeTrue()
        ->and($table->getFiltersFormColumns())->toBe(1)
        ->and($table->getFiltersResetActionPosition())->toBe(FiltersResetActionPosition::Header)
        ->and($table->getFiltersFormMaxHeight())->toBeNull()
        ->and($table->getFiltersFormWidth())->toBeNull();
});

it('re-keys filters by name, binds each back to the table, and replaces the previous set', function (): void {
    $first = Filter::make('isPublished');

    $table = filterConfigTable(static fn (Table $table): Table => $table->filters([$first]));

    expect(array_keys($table->getFilters()))->toBe(['isPublished'])
        ->and($first->getTable())->toBe($table)
        ->and($table->getFilter('isPublished'))->toBe($first)
        ->and($table->isFilterable())->toBeTrue();

    $table->filters([Filter::make('isDraft')]);

    expect(array_keys($table->getFilters()))->toBe(['isDraft']);
});

it('appends with pushFilters instead of replacing', function (): void {
    $table = filterConfigTable(static fn (Table $table): Table => $table->filters([Filter::make('a')]));

    $table->pushFilters([Filter::make('b')]);

    expect(array_keys($table->getFilters()))->toBe(['a', 'b']);
});

it('drops hidden filters unless they are asked for', function (): void {
    $table = filterConfigTable(static fn (Table $table): Table => $table->filters([
        Filter::make('visible'),
        Filter::make('concealed')->hidden(),
    ]));

    expect(array_keys($table->getFilters()))->toBe(['visible'])
        ->and(array_keys($table->getFilters(withHidden: true)))->toBe(['visible', 'concealed'])
        ->and($table->getFilter('concealed'))->toBeNull()
        ->and($table->getFilter('concealed', withHidden: true))->not->toBeNull();
});

it('applies the layout passed alongside the filters', function (): void {
    $table = filterConfigTable(static fn (Table $table): Table => $table->filters(
        [Filter::make('isPublished')],
        FiltersLayout::Hidden,
    ));

    expect($table->getFiltersLayout())->toBe(FiltersLayout::Hidden)
        ->and($table->hasFiltersDropdown())->toBeFalse();
});

it('builds one keyed Group per visible filter, state-pathed and keyed by the filter name', function (): void {
    $table = filterConfigTable(static fn (Table $table): Table => $table->filters([
        Filter::make('isPublished')->columnSpan(3)->columns(2),
        Filter::make('concealed')->hidden(),
    ]));

    $schema = $table->getFiltersFormSchema();

    expect(array_keys($schema))->toBe(['isPublished']);

    $group = $schema['isPublished'];

    expect($group)->toBeInstanceOf(Group::class)
        ->and($group->getStatePath(isAbsolute: false))->toBe('isPublished')
        ->and($group->getKey(isAbsolute: false))->toBe('isPublished')
        ->and($group->getColumnSpan('lg'))->toBe(3)
        ->and($group->getColumnSpan('default'))->toBe(1)
        ->and($group->getColumns('lg'))->toBe(2);
});

it('refuses a filters layout the toolkit does not ship where the chrome consumes it', function (): void {
    $table = filterConfigTable(static fn (Table $table): Table => $table->filters(
        [Filter::make('isPublished')],
        FiltersLayout::AboveContent,
    ));

    expect($table->getFiltersLayout())->toBe(FiltersLayout::AboveContent);

    expect(static fn (): bool => $table->hasFiltersDropdown())
        ->toThrow(LogicException::class, 'AboveContent');
});

it('sums the active indicator count across visible filters', function (): void {
    $table = filterConfigTable(
        static fn (Table $table): Table => $table->filters([
            Filter::make('isPublished'),
            SelectFilter::make('status')->options(['draft' => 'Draft', 'published' => 'Published']),
        ]),
        [
            'isPublished' => ['isActive' => true],
            'status' => ['value' => 'draft'],
        ],
    );

    expect($table->getActiveFiltersCount())->toBe(2)
        ->and($table->isFiltered())->toBeTrue();
});

it('leaves an unconfigured filter Group on the schema defaults', function (): void {
    $table = filterConfigTable(static fn (Table $table): Table => $table->filters([Filter::make('plain')]));

    $group = $table->getFiltersFormSchema()['plain'];

    expect($group->getAllColumns())->toBe([
        'default' => 1,
        'sm' => null,
        'md' => null,
        'lg' => null,
        'xl' => null,
        '2xl' => null,
    ]);
});
