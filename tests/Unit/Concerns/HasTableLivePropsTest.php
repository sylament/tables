<?php

declare(strict_types=1);

use Sylament\Tables\Table;
use Sylament\Tables\Tests\Fixtures\Concerns\TableHost;
use Sylament\Tables\Tests\Fixtures\Concerns\UrlMappedTableHost;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\Attribute\PostHydrate;
use Symfony\UX\LiveComponent\Metadata\UrlMapping;
use Symfony\UX\TwigComponent\Attribute\PostMount;

/**
 * Each bound prop's modifier applied to a fresh `LiveProp`, keyed by the name
 * the host's mapping hook is asked for.
 *
 * @return array<string, LiveProp>
 */
function modifyTableUrlProps(UrlMappedTableHost $host): array
{
    return [
        'page' => $host->modifyTablePageProp(new LiveProp()),
        'sort' => $host->modifyTableSortProp(new LiveProp()),
        'search' => $host->modifyTableSearchProp(new LiveProp()),
        'filters' => $host->modifyTableFiltersProp(new LiveProp()),
        'grouping' => $host->modifyTableGroupingProp(new LiveProp()),
        'columnSearches' => $host->modifyTableColumnSearchesProp(new LiveProp()),
    ];
}

it('declares the table state as LiveProps with the intended writability, hooks and wire bridges', function (string $property, bool $writable, ?string $onUpdated, ?string $dehydrateWith, bool $url, string $fieldName, ?string $modifier = null, ?string $hydrateWith = null): void {
    $attributes = new ReflectionProperty(TableHost::class, $property)->getAttributes(LiveProp::class);

    expect($attributes)->toHaveCount(1);

    $prop = $attributes[0]->newInstance();

    expect($prop->isIdentityWritable())->toBe($writable)
        ->and($prop->onUpdated())->toBe($onUpdated)
        ->and($prop->dehydrateMethod())->toBe($dehydrateWith)
        ->and($prop->url())->toBe($url)
        ->and($prop->modifier())->toBe($modifier)
        ->and($prop->hydrateMethod())->toBe($hydrateWith)
        ->and($prop->calculateFieldName(new TableHost(), $property))->toBe($fieldName);

    foreach (array_filter([$onUpdated, $dehydrateWith, $modifier, $hydrateWith]) as $method) {
        expect(method_exists(TableHost::class, $method))->toBeTrue();
    }
})->with([
    'sort' => ['tableSort', false, null, null, false, 'tableSort', 'modifyTableSortProp'],
    'grouping' => ['tableGrouping', false, null, 'dehydrateTableGrouping', false, 'tableGrouping', 'modifyTableGroupingProp'],
    'page' => ['tablePage', false, null, null, false, 'tablePage', 'modifyTablePageProp'],
    'per page' => ['tableRecordsPerPage', true, 'updatedTableRecordsPerPage', 'dehydrateTableRecordsPerPage', false, 'tableRecordsPerPage'],
    'search' => ['tableSearch', true, 'updatedTableSearch', 'dehydrateTableSearch', false, 'tableSearch', 'modifyTableSearchProp'],
    'filters' => ['tableFilters', true, 'updatedTableFilters', null, false, 'tableFilters', 'modifyTableFiltersProp'],
    'deferred filters' => ['tableDeferredFilters', true, null, null, false, 'tableDeferredFilters'],
    'selected records' => ['selectedTableRecordKeys', true, null, null, false, 'selectedTableRecords'],
    'deselected records' => ['deselectedTableRecords', true, null, null, false, 'deselectedTableRecords'],
    'deselect tracking' => ['isTrackingDeselectedTableRecords', true, null, null, false, 'isTrackingDeselectedTableRecords'],
    'columns' => ['tableColumns', false, null, null, false, 'tableColumns'],
    'deferred columns' => ['tableDeferredColumns', true, null, null, false, 'tableDeferredColumns'],
    'column searches' => ['tableColumnSearches', true, null, 'dehydrateTableColumnSearches', false, 'tableColumnSearches', 'modifyTableColumnSearchesProp', 'hydrateTableColumnSearches'],
]);

/*
 * The mount and hydrate hooks the host flattens into one class, each pinned at
 * the slot it was assigned rather than at whatever `ReflectionClass` order a
 * later trait edit happens to produce — `TwigComponentPass` sorts both lists
 * with a *stable* `arsort`, so two hooks sharing a slot resolve silently.
 */
it('runs each mount and hydrate hook at the priority it was assigned', function (string $method, string $attribute, int $priority): void {
    $attributes = new ReflectionMethod(TableHost::class, $method)->getAttributes($attribute);

    expect($attributes)->toHaveCount(1)
        ->and($attributes[0]->newInstance()->priority)->toBe($priority);
})->with([
    'filters from the query string' => ['mountTableFiltersFromQueryString', PostMount::class, 20],
    'the persisted state read' => ['mountTablePersistedState', PostMount::class, 10],
    'the seeded sort heal' => ['healSeededTableSort', PostMount::class, 5],
    'the per-column seed' => ['mountTableColumnSearches', PostMount::class, 3],
    'the grouping heal and seed' => ['mountTableGrouping', PostMount::class, 2],
    'the filters mount' => ['mountTableFilters', PostMount::class, 0],
    'the filters form re-cache' => ['afterTableFiltersFormHydrated', PostHydrate::class, 10],
    'the per-column compare' => ['afterTableColumnSearchesHydrated', PostHydrate::class, -5],
]);

it('exposes the table state mutations as live actions with live args', function (string $method, array $argNames): void {
    $reflection = new ReflectionMethod(TableHost::class, $method);

    expect($reflection->getAttributes(LiveAction::class))->toHaveCount(1);

    foreach ($reflection->getParameters() as $index => $parameter) {
        expect($parameter->getAttributes(LiveArg::class))->toHaveCount(1)
            ->and($parameter->getName())->toBe($argNames[$index]);
    }
})->with([
    'sortTable' => ['sortTable', ['column', 'direction']],
    'groupTable' => ['groupTable', ['group', 'direction']],
    'gotoTablePage' => ['gotoTablePage', ['page']],
    'applyTableFilters' => ['applyTableFilters', []],
    'removeTableFilter' => ['removeTableFilter', ['filterName', 'field']],
    'removeTableFilters' => ['removeTableFilters', []],
    'resetTableFiltersForm' => ['resetTableFiltersForm', []],
    'resetTableSearch' => ['resetTableSearch', []],
    'resetTableColumnSearch' => ['resetTableColumnSearch', ['column']],
    'resetTableColumnSearches' => ['resetTableColumnSearches', []],
    'selectAllTableRecords' => ['selectAllTableRecords', []],
    'applyTableColumnManager' => ['applyTableColumnManager', ['state']],
    'resetTableColumnManager' => ['resetTableColumnManager', []],
]);

it('leaves every bound LiveProp untouched when the host maps nothing', function (): void {
    foreach (modifyTableUrlProps(new UrlMappedTableHost()) as $liveProp) {
        expect($liveProp->url())->toBeFalse();
    }
});

it('binds a LiveProp to the URL under the name the host maps for it', function (): void {
    $mappings = [
        'page' => new UrlMapping(as: 'articlesPage'),
        'sort' => new UrlMapping(as: 'sort'),
        'search' => new UrlMapping(as: 'search'),
        'filters' => new UrlMapping(as: 'filters'),
        'grouping' => new UrlMapping(as: 'grouping'),
        'columnSearches' => new UrlMapping(as: 'columnSearches'),
    ];

    foreach (modifyTableUrlProps(new UrlMappedTableHost($mappings)) as $prop => $liveProp) {
        $url = $liveProp->url();

        expect($url)->toBeInstanceOf(UrlMapping::class);
        assert($url instanceof UrlMapping);
        expect($url->as)->toBe($mappings[$prop]->as);
    }
});

it('maps one prop without touching its siblings', function (): void {
    $liveProps = modifyTableUrlProps(new UrlMappedTableHost(['sort' => new UrlMapping(as: 'sort')]));

    expect($liveProps['sort']->url())->toBeInstanceOf(UrlMapping::class)
        ->and($liveProps['page']->url())->toBeFalse()
        ->and($liveProps['search']->url())->toBeFalse()
        ->and($liveProps['filters']->url())->toBeFalse()
        ->and($liveProps['grouping']->url())->toBeFalse()
        ->and($liveProps['columnSearches']->url())->toBeFalse();
});

it('builds no table while modifying a prop, so nothing resolves before the component is hydrated', function (): void {
    $host = new UrlMappedTableHost(
        mappings: [
            'page' => new UrlMapping(as: 'page'),
            'sort' => new UrlMapping(as: 'sort'),
            'search' => new UrlMapping(as: 'search'),
            'filters' => new UrlMapping(as: 'filters'),
            'grouping' => new UrlMapping(as: 'grouping'),
            'columnSearches' => new UrlMapping(as: 'columnSearches'),
        ],
        refusesTable: true,
    );

    foreach (modifyTableUrlProps($host) as $liveProp) {
        expect($liveProp->url())->toBeInstanceOf(UrlMapping::class);
    }

    expect(fn (): Table => $host->getTable())->toThrow(LogicException::class);
});
