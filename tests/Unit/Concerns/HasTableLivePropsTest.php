<?php

declare(strict_types=1);

use Sylament\Tables\Tests\Fixtures\Concerns\TableHost;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;

it('declares the table state as LiveProps with the intended writability, hooks and wire bridges', function (string $property, bool $writable, ?string $onUpdated, ?string $dehydrateWith): void {
    $attributes = new ReflectionProperty(TableHost::class, $property)->getAttributes(LiveProp::class);

    expect($attributes)->toHaveCount(1);

    $prop = $attributes[0]->newInstance();

    expect($prop->isIdentityWritable())->toBe($writable)
        ->and($prop->onUpdated())->toBe($onUpdated)
        ->and($prop->dehydrateMethod())->toBe($dehydrateWith);

    foreach (array_filter([$onUpdated, $dehydrateWith]) as $method) {
        expect(method_exists(TableHost::class, $method))->toBeTrue();
    }
})->with([
    'sort' => ['tableSort', false, null, null],
    'page' => ['tablePage', false, null, null],
    'per page' => ['tableRecordsPerPage', true, 'updatedTableRecordsPerPage', 'dehydrateTableRecordsPerPage'],
    'search' => ['tableSearch', true, 'updatedTableSearch', 'dehydrateTableSearch'],
]);

it('exposes sortTable and gotoTablePage as live actions with live args', function (string $method, array $argNames): void {
    $reflection = new ReflectionMethod(TableHost::class, $method);

    expect($reflection->getAttributes(LiveAction::class))->toHaveCount(1);

    foreach ($reflection->getParameters() as $index => $parameter) {
        expect($parameter->getAttributes(LiveArg::class))->toHaveCount(1)
            ->and($parameter->getName())->toBe($argNames[$index]);
    }
})->with([
    'sortTable' => ['sortTable', ['column', 'direction']],
    'gotoTablePage' => ['gotoTablePage', ['page']],
]);
