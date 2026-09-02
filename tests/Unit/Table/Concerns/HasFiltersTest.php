<?php

declare(strict_types=1);

use Sylament\Actions\Action;
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

it('refuses a filters layout the toolkit does not ship where the chrome consumes it', function (FiltersLayout $layout): void {
    $table = filterConfigTable(static fn (Table $table): Table => $table->filters(
        [Filter::make('isPublished')],
        $layout,
    ));

    expect($table->getFiltersLayout())->toBe($layout);

    expect(static fn (): bool => $table->hasFiltersDropdown())
        ->toThrow(LogicException::class, $layout->name);
})->with([
    FiltersLayout::BeforeContent,
    FiltersLayout::BeforeContentCollapsible,
    FiltersLayout::AfterContent,
    FiltersLayout::AfterContentCollapsible,
]);

it('answers each consumable layout with one flag and refuses none of them', function (
    FiltersLayout $layout,
    bool $dropdown,
    bool $modal,
    bool $trigger,
    bool $above,
    bool $below,
    bool $collapsible,
): void {
    $table = filterConfigTable(static fn (Table $table): Table => $table->filters(
        [Filter::make('isPublished')],
        $layout,
    ));

    expect($table->hasFiltersDropdown())->toBe($dropdown)
        ->and($table->hasFiltersModal())->toBe($modal)
        ->and($table->hasFiltersTrigger())->toBe($trigger)
        ->and($table->hasFiltersAboveContent())->toBe($above)
        ->and($table->hasFiltersBelowContent())->toBe($below)
        ->and($table->hasCollapsibleFilters())->toBe($collapsible);
})->with([
    [FiltersLayout::Dropdown, true, false, true, false, false, false],
    [FiltersLayout::Modal, false, true, true, false, false, false],
    [FiltersLayout::AboveContent, false, false, false, true, false, false],
    [FiltersLayout::AboveContentCollapsible, false, false, false, true, false, true],
    [FiltersLayout::BelowContent, false, false, false, false, true, false],
    [FiltersLayout::Hidden, false, false, false, false, false, false],
]);

it('names the filters modal under the host id', function (): void {
    $host = new FilterStateHost();
    $table = $host->getTable();

    expect($table->getFiltersModalId())->toBe($host->getTableHostId() . '.table.filters');
});

it('falls back to a responsive column count for the layouts that span the content', function (
    FiltersLayout $layout,
    array|int $expected,
): void {
    $table = filterConfigTable(static fn (Table $table): Table => $table->filters(
        [Filter::make('isPublished')],
        $layout,
    ));

    expect($table->getFiltersFormColumns())->toBe($expected);
})->with([
    [FiltersLayout::AboveContent, ['sm' => 2, 'lg' => 3, 'xl' => 4, '2xl' => 5]],
    [FiltersLayout::AboveContentCollapsible, ['sm' => 2, 'lg' => 3, 'xl' => 4, '2xl' => 5]],
    [FiltersLayout::BelowContent, ['sm' => 2, 'lg' => 3, 'xl' => 4, '2xl' => 5]],
    [FiltersLayout::Dropdown, 1],
    [FiltersLayout::Modal, 1],
    [FiltersLayout::Hidden, 1],
    [FiltersLayout::BeforeContent, 1],
]);

it('lets a configured column count win over the layout fallback', function (): void {
    $table = filterConfigTable(static fn (Table $table): Table => $table
        ->filters([Filter::make('isPublished')], FiltersLayout::AboveContent)
        ->filtersFormColumns(2));

    expect($table->getFiltersFormColumns())->toBe(2);
});

it('builds the trigger action with no click binding of its own', function (): void {
    $table = filterConfigTable(static fn (Table $table): Table => $table->filters([Filter::make('isPublished')]));

    $action = $table->getFiltersTriggerAction();

    expect($action->getName())->toBe('openFilters')
        ->and($action->getLabel())->toBe('Filter')
        ->and($action->getIcon())->toBe('heroicons:funnel-20-solid')
        ->and($action->getColor())->toBe('gray')
        ->and($action->getAction())->toBeNull()
        ->and($action->isLiveComponentClickHandlerEnabled())->toBeFalse()
        ->and($action->getTable())->toBe($table)
        ->and($action->getExtraAttributes())->toBe([]);
});

it('hands the modal-layout trigger the invoker command that opens the dialog', function (): void {
    $table = filterConfigTable(static fn (Table $table): Table => $table->filters(
        [Filter::make('isPublished')],
        FiltersLayout::Modal,
    ));

    expect($table->getFiltersTriggerAction()->getExtraAttributes())->toBe([
        'command' => 'show-modal',
        'commandfor' => $table->getFiltersModalId(),
    ]);
});

it('stamps the invoker command over a customization that replaced the trigger attributes', function (): void {
    $table = filterConfigTable(static fn (Table $table): Table => $table
        ->filters([Filter::make('isPublished')], FiltersLayout::Modal)
        ->filtersTriggerAction(static fn (Action $action): Action => $action->extraAttributes(['data-probe' => 'yes'])));

    // `extraAttributes()` replaces by default, so a stamp made before the
    // closure runs leaves the modal with no opening mechanism at all.
    expect($table->getFiltersTriggerAction()->getExtraAttributes())->toBe([
        'command' => 'show-modal',
        'commandfor' => $table->getFiltersModalId(),
        'data-probe' => 'yes',
    ]);
});

it('stamps the remove-all class over a customization that replaced its attributes', function (): void {
    $table = filterConfigTable(static fn (Table $table): Table => $table
        ->filters([Filter::make('isPublished')])
        ->filtersRemoveAllAction(static fn (Action $action): Action => $action->extraAttributes(['data-probe' => 'yes'])));

    expect($table->getFiltersRemoveAllAction()->getExtraAttributes())->toBe([
        'class' => ['sy-ta-filter-indicators-remove-all-btn'],
        'data-probe' => 'yes',
    ]);
});

it('builds the apply action, its visibility carrying the defer flag', function (): void {
    $deferred = filterConfigTable(static fn (Table $table): Table => $table->filters([Filter::make('isPublished')]));
    $live = filterConfigTable(static fn (Table $table): Table => $table
        ->filters([Filter::make('isPublished')])
        ->deferFilters(false));

    $action = $deferred->getFiltersApplyAction();

    expect($action->getName())->toBe('applyFilters')
        ->and($action->getLabel())->toBe('Apply filters')
        ->and($action->getAction())->toBe('applyTableFilters')
        ->and($action->isVisible())->toBeTrue()
        ->and($action->getTable())->toBe($deferred)
        ->and($live->getFiltersApplyAction()->isVisible())->toBeFalse();
});

it('builds the remove-all action over the live action the chips share', function (): void {
    $table = filterConfigTable(static fn (Table $table): Table => $table->filters([Filter::make('isPublished')]));

    $action = $table->getFiltersRemoveAllAction();

    expect($action->getName())->toBe('removeAllFilters')
        ->and($action->getLabel())->toBe('Remove all filters')
        ->and($action->getIcon())->toBe('heroicons:x-mark-20-solid')
        ->and($action->getColor())->toBe('gray')
        ->and($action->getAction())->toBe('removeTableFilters')
        ->and($action->getTable())->toBe($table)
        ->and($action->getExtraAttributes())->toBe(['class' => ['sy-ta-filter-indicators-remove-all-btn']]);
});

it('passes each chrome action through its customization, falling back to the untouched action', function (): void {
    $seen = [];

    $table = filterConfigTable(function (Table $table) use (&$seen): Table {
        return $table
            ->filters([Filter::make('isPublished')])
            ->filtersTriggerAction(static function (Action $action) use (&$seen): Action {
                $seen[] = $action->getName();

                return $action->label('Narrow');
            })
            ->filtersApplyAction(static function (Action $action) use (&$seen): ?Action {
                $seen[] = $action->getName();

                return null;
            })
            ->filtersRemoveAllAction(static function (Action $action) use (&$seen): Action {
                $seen[] = $action->getName();

                return $action->label('Clear');
            });
    });

    expect($table->getFiltersTriggerAction()->getLabel())->toBe('Narrow')
        ->and($table->getFiltersApplyAction()->getLabel())->toBe('Apply filters')
        ->and($table->getFiltersRemoveAllAction()->getLabel())->toBe('Clear')
        ->and($seen)->toBe(['openFilters', 'applyFilters', 'removeAllFilters']);
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
