<?php

declare(strict_types=1);

use Sylament\Tables\Tests\Fixtures\Concerns\TableHost;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;

it('declares the table state as LiveProps with the intended writability', function (string $property, bool $writable, ?string $onUpdated): void {
    $attributes = new ReflectionProperty(TableHost::class, $property)->getAttributes(LiveProp::class);

    expect($attributes)->toHaveCount(1);

    $prop = $attributes[0]->newInstance();

    expect($prop->isIdentityWritable())->toBe($writable)
        ->and($prop->onUpdated())->toBe($onUpdated);
})->with([
    'sort' => ['tableSort', false, null],
    'page' => ['tablePage', false, null],
    'per page' => ['tableRecordsPerPage', true, 'updatedTableRecordsPerPage'],
    'search' => ['tableSearch', true, 'updatedTableSearch'],
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
