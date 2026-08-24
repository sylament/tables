<?php

declare(strict_types=1);

use Sylament\Tables\Tests\Fixtures\Concerns\TableHost;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;

it('declares the table state as LiveProps with the intended writability, hooks and wire bridges', function (string $property, bool $writable, ?string $onUpdated, ?string $dehydrateWith, bool $url, string $fieldName): void {
    $attributes = new ReflectionProperty(TableHost::class, $property)->getAttributes(LiveProp::class);

    expect($attributes)->toHaveCount(1);

    $prop = $attributes[0]->newInstance();

    expect($prop->isIdentityWritable())->toBe($writable)
        ->and($prop->onUpdated())->toBe($onUpdated)
        ->and($prop->dehydrateMethod())->toBe($dehydrateWith)
        ->and($prop->url())->toBe($url)
        ->and($prop->calculateFieldName(new TableHost(), $property))->toBe($fieldName);

    foreach (array_filter([$onUpdated, $dehydrateWith]) as $method) {
        expect(method_exists(TableHost::class, $method))->toBeTrue();
    }
})->with([
    'sort' => ['tableSort', false, null, null, false, 'tableSort'],
    'page' => ['tablePage', false, null, null, false, 'tablePage'],
    'per page' => ['tableRecordsPerPage', true, 'updatedTableRecordsPerPage', 'dehydrateTableRecordsPerPage', false, 'tableRecordsPerPage'],
    'search' => ['tableSearch', true, 'updatedTableSearch', 'dehydrateTableSearch', false, 'tableSearch'],
    'filters' => ['tableFilters', true, 'updatedTableFilters', null, false, 'tableFilters'],
    'deferred filters' => ['tableDeferredFilters', true, null, null, false, 'tableDeferredFilters'],
    'selected records' => ['selectedTableRecordKeys', true, null, null, false, 'selectedTableRecords'],
    'deselected records' => ['deselectedTableRecords', true, null, null, false, 'deselectedTableRecords'],
    'deselect tracking' => ['isTrackingDeselectedTableRecords', true, null, null, false, 'isTrackingDeselectedTableRecords'],
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
    'gotoTablePage' => ['gotoTablePage', ['page']],
    'applyTableFilters' => ['applyTableFilters', []],
    'removeTableFilter' => ['removeTableFilter', ['filterName', 'field']],
    'removeTableFilters' => ['removeTableFilters', []],
    'resetTableFiltersForm' => ['resetTableFiltersForm', []],
    'resetTableSearch' => ['resetTableSearch', []],
    'selectAllTableRecords' => ['selectAllTableRecords', []],
]);
