<?php

declare(strict_types=1);

namespace Sylament\Tables\Tests\Integration;

use Sylament\Forms\Components\Field;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;

uses(InteractsWithLiveComponents::class);

/*
 * The filter round trip end to end, over a real live-component request cycle:
 * the draft bag, the apply action, chip removal, remove-all, the form reset, the
 * live-mode re-filter, the search chip and record resolution inside the filtered
 * set. Everything below the wire — the skip chain, the DQL each filter composes,
 * the indicator derivation — is pinned in the Unit bucket; this file pins that
 * the props, actions and schema state paths agree once hydration is involved.
 *
 * Every filter edit is written at the field's own model path, in both modes,
 * because that is the model the panel renders and the two paths differ below it:
 * deferred fields carry `norender|tableDeferredFilters.<filter>.<field>`, live
 * fields carry `tableFilters.<filter>.<field>`. Do not collapse those into one
 * whole-prop `set()` — it replaces the bag, and a filter whose key the
 * replacement drops then applies unconditionally, silently and with no chip, per
 * `docs/progress/tables/has-table.md`. Exactly one whole-prop write to a filter
 * bag survives, and it is there to pin `updatedTableFilters()`, which is the only
 * thing that shape reaches; the two `tableSearch` writes are a different prop,
 * where a whole-prop write is the only shape there is.
 */

it('leaves the rows alone until the draft is applied, then resets the page', function (): void {
    $component = $this->createLiveComponent('FiltersTable')
        ->call('gotoTablePage', ['page' => 2]);

    expect((string) $component->render())->toContain('Let Down');

    $component->set('tableDeferredFilters.plays.value', '200');

    expect((string) $component->render())->toContain('Let Down')
        ->and($component->component()->tablePage)->toBe(2);

    $component->call('applyTableFilters');

    $host = $component->component();
    $html = (string) $component->render();

    expect($host->tablePage)->toBe(1)
        ->and($host->tableFilters)->toBe($host->tableDeferredFilters)
        ->and($host->tableFilters['plays'])->toBe(['value' => '200'])
        ->and($host->tableFilters['popular'])->toBe(['isActive' => false])
        ->and($html)->toContain('Paranoid Android')
        ->and($html)->toContain('Exit Music')
        ->and($html)->not->toContain('Let Down');
});

it("binds every component of a filter schema under that filter's own group", function (): void {
    $form = $this->createLiveComponent('FiltersTable')->component()->getTableFiltersForm();
    $fields = $form->getComponentByStatePath('tableDeferredFilters.playsRange')?->getChildSchema()?->getComponents(withActions: false) ?? [];

    expect($fields)->toHaveCount(2)
        ->and(array_map(static fn (Field $field): ?string => $field->getName(), array_values($fields)))->toBe(['from', 'until']);
});

it('removes exactly the field a chip names and leaves its sibling standing', function (): void {
    $component = $this->createLiveComponent('FiltersTable')
        ->set('tableDeferredFilters.playsRange.from', '150')
        ->set('tableDeferredFilters.playsRange.until', '250')
        ->call('applyTableFilters');

    expect((string) $component->render())->toContain('Paranoid Android')
        ->and((string) $component->render())->not->toContain('Airbag');

    $component->call('removeTableFilter', ['filterName' => 'playsRange', 'field' => 'from']);

    $state = $component->component()->tableFilters['playsRange'];

    expect($state['from'])->toBeNull()
        ->and($state['until'])->toBe('250')
        ->and((string) $component->render())->toContain('Airbag');
});

it('clears every filter and the search term on remove-all', function (): void {
    $component = $this->createLiveComponent('FiltersTable')
        ->set('tableSearch', 'Airbag')
        ->set('tableDeferredFilters.plays.value', '200')
        ->set('tableDeferredFilters.popular.isActive', true)
        ->call('applyTableFilters');

    expect((string) $component->render())->toContain('Active filters');

    $component->call('removeTableFilters');

    $host = $component->component();
    $html = (string) $component->render();

    expect($host->tableSearch)->toBe('')
        ->and($host->tableFilters['plays']['value'])->toBeNull()
        ->and($host->tableFilters['popular']['isActive'])->toBeFalse()
        ->and($host->tablePage)->toBe(1)
        ->and($html)->toContain('Airbag')
        ->and($html)->not->toContain('Active filters');
});

it('restores the field defaults when the filters form is reset', function (): void {
    $component = $this->createLiveComponent('LiveFiltersTable');

    expect($component->component()->tableFilters['plays']['value'])->toBe('200');

    $component->set('tableFilters.plays.value', '100');

    expect((string) $component->render())->toContain('Airbag')
        ->and((string) $component->render())->not->toContain('Exit Music');

    $component->call('resetTableFiltersForm');

    $html = (string) $component->render();

    expect($component->component()->tableFilters['plays']['value'])->toBe('200')
        ->and($html)->toContain('Exit Music')
        ->and($html)->not->toContain('Airbag');
});

/*
 * The panel's own live path, which no whole-prop write reaches: a nested write
 * fires no `onUpdated` hook at all, so the page reset can only arrive through
 * the chain this trait's `#[PostHydrate(priority: 10)]` rebuild exists to keep
 * ordered — rebuild, then schemas' `-10` old-state diff, then the field's
 * `afterStateUpdated`, then `handleTableFilterUpdates()`. Paging off page 1
 * first is what makes the reset non-vacuous, and it is load-bearing twice over:
 * the filtered set is two rows deep at two per page, so a page that failed to
 * reset would render the empty state and none of the asserted titles.
 */
it('re-filters and resets the page from a panel field write when filters are live', function (): void {
    $component = $this->createLiveComponent('LiveFiltersTable')
        ->call('gotoTablePage', ['page' => 2]);

    expect($component->component()->tablePage)->toBe(2)
        ->and((string) $component->render())->not->toContain('Paranoid Android');

    $component->set('tableFilters.plays.value', '100');

    $host = $component->component();
    $html = (string) $component->render();

    expect($host->tablePage)->toBe(1)
        ->and($host->tableFilters['plays']['value'])->toBe('100')
        ->and($html)->toContain('Airbag')
        ->and($html)->toContain('Let Down')
        ->and($html)->not->toContain('Paranoid Android')
        ->and($html)->not->toContain('Exit Music');
});

it('re-filters from the updatedTableFilters hook on a whole-prop write', function (): void {
    $component = $this->createLiveComponent('LiveFiltersTable')
        ->call('gotoTablePage', ['page' => 2])
        ->set('tableFilters', ['plays' => ['value' => '100']]);

    $html = (string) $component->render();

    expect($component->component()->tablePage)->toBe(1)
        ->and($html)->toContain('Airbag')
        ->and($html)->toContain('Let Down')
        ->and($html)->not->toContain('Paranoid Android');
});

it('clears the search from its own chip action', function (): void {
    $component = $this->createLiveComponent('LiveFiltersTable')
        ->set('tableSearch', 'Exit');

    expect((string) $component->render())->toContain('Search: Exit');

    $component->call('resetTableSearch');

    expect($component->component()->tableSearch)->toBe('')
        ->and($component->component()->tablePage)->toBe(1)
        ->and((string) $component->render())->not->toContain('Search: Exit');
});

it('cannot mount a record action on a record the filters excluded', function (): void {
    $component = $this->createLiveComponent('FiltersTable')
        ->set('tableDeferredFilters.plays.value', '200')
        ->call('applyTableFilters')
        ->call('mountAction', ['name' => 'inspect', 'arguments' => [], 'context' => ['table' => true, 'recordKey' => '1']]);

    expect($component->component()->mountedActions)->toBe([]);
});

it("reaches an excluded filter's hidden record when the filter opts out of resolution", function (): void {
    $component = $this->createLiveComponent('FiltersTable', ['excludesPlaysFilterWhenResolvingRecord' => true])
        ->set('tableDeferredFilters.plays.value', '200')
        ->call('applyTableFilters')
        ->call('mountAction', ['name' => 'inspect', 'arguments' => [], 'context' => ['table' => true, 'recordKey' => '1']]);

    expect((string) $component->render())->toContain('Inspect &quot;Airbag&quot;');
});
