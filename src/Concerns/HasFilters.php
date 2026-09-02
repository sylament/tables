<?php

declare(strict_types=1);

namespace Sylament\Tables\Concerns;

use Doctrine\ORM\QueryBuilder;
use LogicException;
use Sylament\Forms\Components\Field;
use Sylament\Schemas\Concerns\HasSchemas;
use Sylament\Schemas\Contracts\InteractsWithSchemas;
use Sylament\Schemas\Schema;
use Sylament\Tables\Filters\BaseFilter;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\Attribute\PostHydrate;
use Symfony\UX\LiveComponent\Metadata\UrlMapping;
use Symfony\UX\TwigComponent\Attribute\PostMount;

use function Sylament\Support\filled;

trait HasFilters
{
    /**
     * The applied filter state — what the query and the indicators read.
     *
     * @var array<string, mixed>
     */
    #[LiveProp(writable: true, onUpdated: 'updatedTableFilters', modifier: 'modifyTableFiltersProp')]
    public array $tableFilters = [];

    public function modifyTableFiltersProp(LiveProp $liveProp): LiveProp
    {
        $mapping = $this->getTableUrlMapping('filters');

        return $mapping instanceof UrlMapping ? $liveProp->withUrl($mapping) : $liveProp;
    }

    /**
     * The draft the filters form binds to while filters are deferred.
     *
     * @var array<string, mixed>
     */
    #[LiveProp(writable: true)]
    public array $tableDeferredFilters = [];

    /**
     * One filter's applied slice, or null when it holds nothing or holds
     * something that is not a state map.
     *
     * @return array<string, mixed>|null
     */
    public function getTableFilterState(string $name): ?array
    {
        $state = $this->tableFilters[$this->parseTableFilterName($name)] ?? null;

        return self::isFilterStateMap($state) ? $state : null;
    }

    /**
     * The slice the form is bound to — the draft while deferring, the applied
     * state otherwise.
     *
     * @return array<string, mixed>|null
     */
    public function getTableFilterFormState(string $name): ?array
    {
        $bag = $this->getTable()->hasDeferredFilters() ? $this->tableDeferredFilters : $this->tableFilters;

        $state = $bag[$this->parseTableFilterName($name)] ?? null;

        return self::isFilterStateMap($state) ? $state : null;
    }

    /**
     * A filter's slice is a field-keyed map — the bags are writable props, so
     * anything else the client puts there (a scalar, or a list) is not one.
     *
     * @phpstan-assert-if-true array<string, mixed> $state
     */
    private static function isFilterStateMap(mixed $state): bool
    {
        if (!is_array($state)) {
            return false;
        }

        return array_all(array_keys($state), static fn (int|string $field): bool => is_string($field));
    }

    public function parseTableFilterName(string $name): string
    {
        if (!is_a($name, BaseFilter::class, allow_string: true)) {
            return $name;
        }

        return $name::getDefaultName() ?? $name;
    }

    public function getTableFiltersForm(): Schema
    {
        if (!$this instanceof InteractsWithSchemas) {
            throw new LogicException('Table filters need a schema host: ' . static::class . ' must implement ' . InteractsWithSchemas::class . ' and use the ' . HasSchemas::class . ' trait.');
        }

        if ($this->hasCachedSchema('tableFiltersForm')) {
            return $this->getSchema('tableFiltersForm');
        }

        $table = $this->getTable();

        $schema = $this->makeSchema()
            ->components($table->getFiltersFormSchema())
            ->columns($table->getFiltersFormColumns());

        $recordClass = $table->getRecordClass();

        if ($recordClass !== null) {
            $schema->record($recordClass);
        }

        if ($table->hasDeferredFilters()) {
            $schema->statePath('tableDeferredFilters');
        } else {
            $schema->statePath('tableFilters')->live();

            foreach ($schema->getFlatComponents(withActions: false) as $component) {
                if (!$component instanceof Field) {
                    continue;
                }

                $component->afterStateUpdated(function (): void {
                    $this->handleTableFilterUpdates();
                });
            }
        }

        $this->cacheSchema('tableFiltersForm', $schema);

        return $schema;
    }

    /**
     * The filter bag's own query-string seed, running above the session hook so
     * that the URL still wins over what the session remembers.
     *
     * UX seeds every other bound prop for us and refuses this one: it assigns a
     * seeded value only when the prop's resolved type accepts it, and
     * `property-info` reads `removeTableFilter()` as a *remover* for a
     * `tableFilter` singular, inferring `list<string>` for the bag — which no
     * filter state ever is. Reading the parameter here is immune to that, and
     * idempotent with UX's own seeding wherever an application's property-info
     * chain does resolve the bag from its docblock.
     *
     * Which is also why declining a malformed bag has to empty it rather than
     * return: UX has already assigned whatever the parameter carried, so leaving
     * it alone would keep a list in a filter-name-keyed prop and suppress every
     * filter's declared default. The parameter's absence stays a no-op — there
     * is nothing for either seeding to have assigned.
     */
    #[PostMount(priority: 20)]
    public function mountTableFiltersFromQueryString(): void
    {
        $mapping = $this->getTableUrlMapping('filters');

        if (!$mapping instanceof UrlMapping) {
            return;
        }

        $query = $this->getTableStateMainRequest()?->query;
        $parameter = $mapping->as ?? 'tableFilters';

        if ($query === null || !$query->has($parameter)) {
            return;
        }

        $seeded = $query->all()[$parameter];

        $this->tableFilters = self::isPersistedTableFilterBag($seeded) ? $seeded : [];
    }

    /**
     * Seed each filter's declared default, then — while deferring — copy the
     * draft across so those defaults are applied state from the first render.
     *
     * A bag already holding something at this point was seeded from the query
     * string or read back from the session, and it fills the form *instead of*
     * the defaults — so the copy into the applied bag reads the filled form
     * rather than the draft as it stood before, which is what keeps the seed
     * from being overwritten by state nobody asked for.
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    #[PostMount]
    public function mountTableFilters(array $data): array
    {
        $table = $this->getTable();

        if (!$table->isFilterable()) {
            return $data;
        }

        $seededFilters = null;

        if ($this->tableFilters !== []) {
            $seededFilters = self::normalizeTableFilterValuesFromQueryString($this->tableFilters);

            $this->tableFilters = $seededFilters;
        }

        $this->getTableFiltersForm()->fill($seededFilters);

        if ($table->hasDeferredFilters()) {
            $this->tableFilters = $this->tableDeferredFilters;
        }

        $this->persistTableFiltersInSession();

        return $data;
    }

    /**
     * A query string carries no types, so the three scalars a filter can hold
     * arrive as their own names — `'null'`, `'false'`, `'true'` — and a ternary
     * filter reading the string `'false'` would take its *true* arm.
     *
     * @template TKey of array-key
     *
     * @param array<TKey, mixed> $state
     *
     * @return array<TKey, mixed>
     */
    private static function normalizeTableFilterValuesFromQueryString(array $state): array
    {
        foreach ($state as $key => $value) {
            if (is_array($value)) {
                $state[$key] = self::normalizeTableFilterValuesFromQueryString($value);

                continue;
            }

            $state[$key] = match ($value) {
                'null' => null,
                'false' => false,
                'true' => true,
                default => $value,
            };
        }

        return $state;
    }

    /**
     * The filters form is cache-only, so it must be rebuilt above
     * `callAfterSchemaStateUpdated()`'s -10 or the walk finds nothing and every
     * field hook inside it silently never fires.
     */
    #[PostHydrate(priority: 10)]
    public function afterTableFiltersFormHydrated(): void
    {
        if (!$this instanceof InteractsWithSchemas) {
            return;
        }

        if (!$this->getTable()->isFilterable()) {
            return;
        }

        $this->getTableFiltersForm();
    }

    public function updatedTableFilters(mixed $previousValue = null): void
    {
        if ($previousValue === $this->tableFilters) {
            return;
        }

        if ($this->getTable()->hasDeferredFilters()) {
            $this->tableDeferredFilters = $this->tableFilters;
        }

        $this->handleTableFilterUpdates();
    }

    protected function handleTableFilterUpdates(): void
    {
        if ($this->getTable()->shouldDeselectAllRecordsWhenFiltered()) {
            $this->deselectAllTableRecords();
        }

        $this->tablePage = 1;
        $this->flushCachedTableRecords();
        $this->persistTableFiltersInSession();
    }

    /**
     * Both filter passes over one builder: every visible filter's base-query
     * modification first, then every visible filter's own constraint. Record
     * resolution skips the second pass for filters that opted out of it.
     */
    protected function applyFiltersToTableQuery(QueryBuilder $query, bool $isResolvingRecord = false): QueryBuilder
    {
        $filters = $this->getTable()->getFilters();

        foreach ($filters as $filterName => $filter) {
            $filter->applyToBaseQuery($query, $this->getTableFilterState($filterName) ?? []);
        }

        foreach ($filters as $filterName => $filter) {
            if ($isResolvingRecord && $filter->shouldExcludeWhenResolvingRecord()) {
                continue;
            }

            $filter->apply($query, $this->getTableFilterState($filterName) ?? []);
        }

        return $query;
    }

    #[LiveAction]
    public function applyTableFilters(): void
    {
        $this->tableFilters = $this->tableDeferredFilters;

        $this->handleTableFilterUpdates();
    }

    #[LiveAction]
    public function removeTableFilter(#[LiveArg] string $filterName, #[LiveArg] ?string $field = null): void
    {
        $this->resetTableFilterState($filterName, $field);

        if ($this->getTable()->hasDeferredFilters()) {
            $this->applyTableFilters();

            return;
        }

        $this->handleTableFilterUpdates();
    }

    #[LiveAction]
    public function removeTableFilters(): void
    {
        foreach ($this->getTable()->getFilters() as $filterName => $filter) {
            foreach ($filter->getIndicators() as $indicator) {
                if (!$indicator->isRemovable()) {
                    continue 2;
                }
            }

            $this->resetTableFilterState($filterName, null);
        }

        $this->resetTableSearch();

        if ($this->getTable()->hasDeferredFilters()) {
            $this->applyTableFilters();

            return;
        }

        $this->handleTableFilterUpdates();
    }

    #[LiveAction]
    public function resetTableFiltersForm(): void
    {
        $this->getTableFiltersForm()->fill();

        if ($this->getTable()->hasDeferredFilters()) {
            $this->applyTableFilters();

            return;
        }

        $this->handleTableFilterUpdates();
    }

    /**
     * Reset the schema fields backing one filter — the whole group, or only
     * `$field` when the removed indicator named one that the group actually
     * carries. The write goes through the fields, so the form's state path
     * decides which bag receives it.
     */
    private function resetTableFilterState(string $filterName, ?string $field): void
    {
        $filter = $this->getTable()->getFilter($filterName);

        if ($filter === null) {
            return;
        }

        $form = $this->getTableFiltersForm();
        $formStatePath = $form->getStatePath();
        $groupStatePath = filled($formStatePath)
            ? "{$formStatePath}.{$filterName}"
            : $filterName;

        $childSchema = $form->getComponentByStatePath($groupStatePath)?->getChildSchema();

        if (!$childSchema instanceof Schema) {
            return;
        }

        $fields = [];

        foreach ($childSchema->getFlatComponents(withActions: false) as $component) {
            if (!$component instanceof Field) {
                continue;
            }

            $fieldName = $component->getName();

            if ($fieldName === null) {
                continue;
            }

            $fields[$fieldName] = $component;
        }

        if (filled($field) && array_key_exists($field, $fields)) {
            $fields = [$field => $fields[$field]];
        }

        $resetState = $filter->getResetState();

        foreach ($fields as $fieldName => $fieldComponent) {
            if (array_key_exists($fieldName, $resetState)) {
                $fieldComponent->state($resetState[$fieldName]);

                continue;
            }

            $state = $fieldComponent->getState();

            $fieldComponent->state(match (true) {
                is_array($state) => [],
                is_bool($state) => $fieldComponent->hasNullableBooleanState() ? null : false,
                default => null,
            });
        }
    }
}
