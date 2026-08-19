<?php

declare(strict_types=1);

namespace Sylament\Tables\Tests\Integration\Concerns;

use LogicException;
use ReflectionMethod;
use Sylament\Schemas\Components\Group;
use Sylament\Schemas\Schema;
use Sylament\Tables\Filters\Filter;
use Sylament\Tables\Table;
use Sylament\Tables\Tests\Fixtures\Concerns\SchemaTableHost;
use Symfony\UX\LiveComponent\Attribute\PostHydrate;
use Symfony\UX\TwigComponent\Attribute\PostMount;

function filtersFormHost(bool $deferred = true): SchemaTableHost
{
    return new SchemaTableHost(static fn (Table $table): Table => $table
        ->filters([
            Filter::make('isPublished')->default(true),
            Filter::make('isFeatured'),
        ])
        ->deferFilters($deferred));
}

it('roots the deferred filters form at the draft bag, one Group per filter', function (): void {
    $form = filtersFormHost()->getTableFiltersForm();

    $groups = $form->getComponents();

    expect($form->getStatePath())->toBe('tableDeferredFilters')
        ->and(array_keys($groups))->toBe(['isPublished', 'isFeatured'])
        ->and($groups['isPublished'])->toBeInstanceOf(Group::class)
        ->and($groups['isPublished']->getStatePath())->toBe('tableDeferredFilters.isPublished')
        ->and($form->getComponentByStatePath('tableDeferredFilters.isPublished.isActive'))->not->toBeNull();
});

it('roots the live filters form at the applied bag and makes every leaf field live', function (): void {
    $form = filtersFormHost(deferred: false)->getTableFiltersForm();

    $field = $form->getComponentByStatePath('tableFilters.isPublished.isActive');

    expect($form->getStatePath())->toBe('tableFilters')
        ->and($form->isLive())->toBeTrue()
        ->and($field)->not->toBeNull()
        ->and($field->isLive())->toBeTrue();
});

it('fills the declared defaults into the draft bag and copies them across on mount', function (): void {
    $host = filtersFormHost();

    $host->mountTableFilters([]);

    expect($host->tableDeferredFilters)->toBe([
        'isPublished' => ['isActive' => true],
        'isFeatured' => ['isActive' => false],
    ])
        ->and($host->tableFilters)->toBe($host->tableDeferredFilters)
        ->and($host->getTableFilterState('isPublished'))->toBe(['isActive' => true]);
});

it('fills straight into the applied bag in live mode, with nothing to copy', function (): void {
    $host = filtersFormHost(deferred: false);

    $host->mountTableFilters([]);

    expect($host->tableFilters)->toBe([
        'isPublished' => ['isActive' => true],
        'isFeatured' => ['isActive' => false],
    ])
        ->and($host->tableDeferredFilters)->toBe([]);
});

it('re-caches the cache-only filters form above the after-state-updated walk', function (): void {
    $host = filtersFormHost();

    expect(new ReflectionMethod($host, 'afterTableFiltersFormHydrated')->getAttributes(PostHydrate::class)[0]->newInstance()->priority)->toBe(10);

    $host->afterTableFiltersFormHydrated();

    expect($host->hasCachedSchema('tableFiltersForm'))->toBeTrue()
        ->and(array_keys($host->getCachedSchemas()))->toBe(['tableFiltersForm']);

    expect(new ReflectionMethod($host, 'mountTableFilters')->getAttributes(PostMount::class))->toHaveCount(1);
});

it('hands each filter the child schema its own group owns', function (): void {
    $host = filtersFormHost();
    $filters = $host->getTable()->getFilters();

    $host->getTableFiltersForm();

    $schema = $filters['isPublished']->getSchema();

    expect($schema)->toBeInstanceOf(Schema::class)
        ->and($schema->getStatePath())->toBe('tableDeferredFilters.isPublished')
        ->and($schema->getComponentByStatePath('tableDeferredFilters.isPublished.isActive'))->not->toBeNull()
        ->and($filters['isFeatured']->getSchema()->getStatePath())->toBe('tableDeferredFilters.isFeatured');
});

it('refuses a filter that has no group in the filters form', function (): void {
    $host = filtersFormHost();
    $orphan = Filter::make('orphan')->table($host->getTable());

    expect(static fn (): object => $orphan->getSchema())
        ->toThrow(LogicException::class, 'has no group in the filters form');
});
