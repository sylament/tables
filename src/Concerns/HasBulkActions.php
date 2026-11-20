<?php

declare(strict_types=1);

namespace Sylament\Tables\Concerns;

use Closure;
use Doctrine\ORM\QueryBuilder;
use LogicException;
use Sylament\Schemas\Identity\IdentifierConstraint;
use Sylament\Support\Pagination\Paginator;
use Sylament\Tables\Grouping\Group;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveProp;

trait HasBulkActions
{
    /**
     * The selected record keys, untrusted client input; `fieldName:` keeps the
     * wire and model name Filament's, so the rename is invisible outside this
     * class.
     *
     * @var list<string>
     */
    #[LiveProp(writable: true, fieldName: 'selectedTableRecords')]
    public array $selectedTableRecordKeys = [];

    /**
     * The keys excluded from an inverted selection — read only while tracking.
     *
     * @var list<string>
     */
    #[LiveProp(writable: true)]
    public array $deselectedTableRecords = [];

    #[LiveProp(writable: true)]
    public bool $isTrackingDeselectedTableRecords = false;

    /**
     * @var list<object|array<string, mixed>>|list<string>|null
     */
    private ?array $cachedSelectedTableRecords = null;

    /**
     * Enumerated group keys by group, the blank group under `"\0"` since null is
     * not an array key. One page renders each group header once, but the memo is
     * what keeps a second reader of the same list free.
     *
     * @var array<string, list<string>>
     */
    private array $cachedGroupedSelectableTableRecordKeys = [];

    public function isTableRecordSelected(string $key): bool
    {
        if ($this->tracksDeselectedTableRecords()) {
            return !in_array($key, $this->normalizedSelectedTableRecordKeys($this->deselectedTableRecords), true);
        }

        return in_array($key, $this->normalizedSelectedTableRecordKeys($this->selectedTableRecordKeys), true);
    }

    public function hasSelectedTableRecords(): bool
    {
        return $this->tracksDeselectedTableRecords()
            || $this->normalizedSelectedTableRecordKeys($this->selectedTableRecordKeys) !== [];
    }

    /**
     * The selected records, re-derived from the keys the client sent: hydrated
     * records, or their keys alone under `fetchSelectedRecords(false)`. Nothing
     * in the client's bags proves a record is visible, selectable or extant, so
     * the filters and the selectability callback are re-applied here.
     *
     * @return list<object|array<string, mixed>>|list<string>
     */
    public function getSelectedTableRecords(bool $shouldFetchSelectedRecords = true): array
    {
        if ($this->cachedSelectedTableRecords !== null) {
            return $this->cachedSelectedTableRecords;
        }

        $selectedKeys = $this->normalizedSelectedTableRecordKeys($this->selectedTableRecordKeys);

        if ($selectedKeys === [] && !$this->tracksDeselectedTableRecords()) {
            return $this->cachedSelectedTableRecords = [];
        }

        $table = $this->getTable();

        if (!$table->hasQuery()) {
            $records = $table->getRecords();

            $resolvedRecords = $this->tracksDeselectedTableRecords()
                ? array_diff_key($records, array_flip($this->normalizedSelectedTableRecordKeys($this->deselectedTableRecords)))
                : array_intersect_key($records, array_flip($selectedKeys));

            return $this->cachedSelectedTableRecords = $this->filterSelectableTableRecords(array_values($resolvedRecords));
        }

        if ($this->hasCompositeTableRecordKeys()) {
            return $this->cachedSelectedTableRecords = $this->resolveCompositeSelectedTableRecords($shouldFetchSelectedRecords);
        }

        $query = $this->getSelectedTableRecordsQuery($shouldFetchSelectedRecords);

        if (!$shouldFetchSelectedRecords) {
            return $this->cachedSelectedTableRecords = $this->pluckTableRecordKeys($query);
        }

        /** @var list<object> $records */
        $records = $query->getQuery()->getResult();

        return $this->cachedSelectedTableRecords = $this->filterSelectableTableRecords($records);
    }

    /**
     * The table's query narrowed to the selection through
     * {@see IdentifierConstraint} — the selected keys, or everything but the
     * deselected ones while tracking, in whatever shape the record class's
     * identifier takes. The filters are re-applied
     * only when the table deselects on filtering, which is what makes the
     * surviving selection provably inside them; without that coupling the
     * selection deliberately outlives the filters and must not be constrained.
     * It binds every selected key in one statement; a selection over a record
     * class keyed by several fields that outgrows one batch is read through
     * {@see getSelectedTableRecords()}, which batches.
     */
    public function getSelectedTableRecordsQuery(bool $shouldFetchSelectedRecords = true): QueryBuilder
    {
        $query = $this->createSelectionQuery(
            $shouldFetchSelectedRecords,
            function (QueryBuilder $query, string $rootAlias): ?string {
                [, $recordClass] = $this->resolveTableRecordKeyTarget($query);

                if ($this->tracksDeselectedTableRecords()) {
                    return IdentifierConstraint::notIn($query, $rootAlias, $recordClass, $this->normalizedSelectedTableRecordKeys($this->deselectedTableRecords), 'sylament_selected_record_keys');
                }

                return IdentifierConstraint::in($query, $rootAlias, $recordClass, $this->normalizedSelectedTableRecordKeys($this->selectedTableRecordKeys), 'sylament_selected_record_keys');
            },
        );

        if (!$shouldFetchSelectedRecords) {
            $this->constrainQueryToSelectableTableRecords($query);
        }

        return $query;
    }

    /**
     * The table's query narrowed by one key predicate — none when the predicate
     * has nothing to exclude — eager-loaded on a fetching read and re-filtered
     * where the table deselects on filtering.
     *
     * @param Closure|null $narrow given the query and its root alias, answers the predicate or null
     */
    private function createSelectionQuery(bool $appliesEagerLoading, ?Closure $narrow): QueryBuilder
    {
        $table = $this->getTable();
        $query = $table->getQuery();

        if (!$query instanceof QueryBuilder) {
            throw new LogicException(sprintf('Table [%s] resolves selected records from a query it does not have; a records() table resolves them from its page.', static::class));
        }

        [$rootAlias] = $this->resolveTableRecordKeyTarget($query);

        if ($narrow instanceof Closure) {
            $predicate = $narrow($query, $rootAlias);

            // Nothing left to exclude means every row, so no predicate is emitted:
            // one built from no key at all would exclude everything instead.
            if (is_string($predicate)) {
                $query->andWhere($predicate);
            }
        }

        if ($appliesEagerLoading) {
            foreach ($table->getColumns() as $column) {
                $column->applyEagerLoading($query);
            }
        }

        if ($table->shouldDeselectAllRecordsWhenFiltered()) {
            $this->filterTableQuery($query);
        }

        return $query;
    }

    /**
     * Whether the table keys its records by more than one identifier field.
     */
    private function hasCompositeTableRecordKeys(): bool
    {
        $table = $this->getTable();
        $recordClass = $table->getRecordClass();
        $entityManager = $table->getQueryEntityManager();

        return $recordClass !== null
            && $entityManager !== null
            && $entityManager->getClassMetadata($recordClass)->isIdentifierComposite;
    }

    /**
     * The selection read one batch of keys a statement. Records are read on a
     * fetching read and wherever selectability is a PHP predicate, then filtered
     * and keyed here rather than bound back as a second key list.
     *
     * @return list<object|array<string, mixed>>|list<string>
     */
    private function resolveCompositeSelectedTableRecords(bool $shouldFetchSelectedRecords): array
    {
        $readsRecords = $shouldFetchSelectedRecords || $this->getTable()->checksIfRecordIsSelectable();
        $records = [];
        $keys = [];

        foreach ($this->createCompositeSelectionQueries($shouldFetchSelectedRecords) as $query) {
            if ($readsRecords) {
                /** @var list<object> $batch */
                $batch = $query->getQuery()->getResult();
                array_push($records, ...$batch);

                continue;
            }

            array_push($keys, ...$this->pluckTableRecordKeys($query));
        }

        if ($shouldFetchSelectedRecords) {
            return $this->filterSelectableTableRecords(array_values(array_combine(array_map(spl_object_id(...), $records), $records)));
        }

        if (!$readsRecords) {
            return array_values(array_unique($keys));
        }

        return array_values(array_unique(array_map(
            $this->getTableRecordKey(...),
            $this->filterSelectableTableRecords($records),
        )));
    }

    /**
     * One query a batch of selected keys. While tracking, a deselected bag that
     * fits one batch stays one exclusion; a larger one is subtracted in PHP from
     * every key the table admits.
     *
     * @return list<QueryBuilder>
     */
    private function createCompositeSelectionQueries(bool $appliesEagerLoading): array
    {
        $table = $this->getTable();
        $entityManager = $table->getQueryEntityManager()
            ?? throw new LogicException(sprintf('Table [%s] reads a selection through a query no entity manager runs.', static::class));
        $recordClass = $table->getRecordClass()
            ?? throw new LogicException(sprintf('Table [%s] must have [query()] to resolve records by key.', static::class));

        if ($this->tracksDeselectedTableRecords()) {
            $deselected = array_values(array_unique($this->normalizedSelectedTableRecordKeys($this->deselectedTableRecords)));

            if (count(IdentifierConstraint::chunk($entityManager, $recordClass, $deselected)) <= 1) {
                return [$this->createSelectionQuery(
                    $appliesEagerLoading,
                    static fn (QueryBuilder $query, string $rootAlias): ?string => IdentifierConstraint::notIn($query, $rootAlias, $recordClass, $deselected, 'sylament_selected_record_keys'),
                )];
            }

            $excluded = array_flip($deselected);
            $keys = array_values(array_filter(
                $this->pluckTableRecordKeys($this->createSelectionQuery(false, null)),
                static fn (string $key): bool => !isset($excluded[$key]),
            ));
        } else {
            $keys = $this->normalizedSelectedTableRecordKeys($this->selectedTableRecordKeys);
        }

        return array_map(
            fn (array $batch): QueryBuilder => $this->createSelectionQuery(
                $appliesEagerLoading,
                static fn (QueryBuilder $query, string $rootAlias): string => IdentifierConstraint::in($query, $rootAlias, $recordClass, $batch, 'sylament_selected_record_keys'),
            ),
            IdentifierConstraint::batch($entityManager, $recordClass, $keys),
        );
    }

    /**
     * Every key the user is allowed to select — the whole filtered set, or the
     * current page where selection is page-only. No limit and no chunking, as
     * Filament's has none: a select-all over a million rows sends a million
     * keys.
     *
     * @return list<string>
     */
    public function getAllSelectableTableRecordKeys(): array
    {
        $table = $this->getTable();

        if (!$table->checksIfRecordIsSelectable()) {
            if (!$table->selectsCurrentPageOnly()) {
                return $this->pluckTableRecordKeys($this->getFilteredTableQueryOrFail());
            }

            return array_map(static fn (int|string $key): string => (string) $key, array_keys($table->getRecords()));
        }

        $records = $table->selectsCurrentPageOnly()
            ? $table->getRecords()
            : $this->getFilteredTableRecordsForSelection();

        $keys = [];

        foreach ($records as $key => $record) {
            if (!$table->isRecordSelectable($record)) {
                continue;
            }

            $keys[] = (string) $key;
        }

        return $keys;
    }

    /**
     * The ceiling the selection banner counts against. Null is an answer rather
     * than a failure — the banner falls back to the in-page count and the
     * select-all-across-pages link stays hidden — but no arm here reaches it:
     * a table past the page-only branch always has a query, and a query always
     * has a count.
     */
    public function getAllSelectableTableRecordsCount(): ?int
    {
        $table = $this->getTable();

        if ($table->checksIfRecordIsSelectable()) {
            $records = $table->selectsCurrentPageOnly()
                ? $table->getRecords()
                : $this->getFilteredTableRecordsForSelection();

            return count(array_filter(
                $records,
                static fn (object|array $record): bool => $table->isRecordSelectable($record),
            ));
        }

        if ($table->selectsCurrentPageOnly()) {
            return count($table->getRecords());
        }

        $records = $this->getTableRecords();

        if (!$records instanceof Paginator) {
            return count($records);
        }

        return $records->getTotalRecordCount() ?? $this->getAllTableRecordsCount();
    }

    /**
     * Every selectable key inside one record group — the whole group across the
     * filtered set, or the group's slice of the current page where selection is
     * page-only. `$group` is the group's **string key**, never its title, and it
     * is nullable because a record whose group column is blank forms its own
     * group. No limit and no chunking, as the ungrouped enumeration has none, so
     * a page of N groups on the whole-universe arm costs N queries.
     *
     * @return list<string>
     */
    public function getGroupedSelectableTableRecordKeys(?string $group): array
    {
        $grouping = $this->getTableGrouping();

        if (!$grouping instanceof Group) {
            return [];
        }

        return $this->cachedGroupedSelectableTableRecordKeys[$group ?? "\0"]
            ??= $this->resolveGroupedSelectableTableRecordKeys($grouping, $group);
    }

    /**
     * The cross-page arm of "select all": it answers by writing the enumerated
     * keys into the prop and re-rendering, never by returning them.
     */
    #[LiveAction]
    public function selectAllTableRecords(): void
    {
        $this->isTrackingDeselectedTableRecords = false;
        $this->deselectedTableRecords = [];
        $this->selectedTableRecordKeys = $this->getAllSelectableTableRecordKeys();
        $this->cachedSelectedTableRecords = null;
        $this->cachedGroupedSelectableTableRecordKeys = [];
    }

    /**
     * Clears the server's own selection state, memo included.
     */
    public function deselectAllTableRecords(): void
    {
        $this->selectedTableRecordKeys = [];
        $this->deselectedTableRecords = [];
        $this->isTrackingDeselectedTableRecords = false;
        $this->cachedSelectedTableRecords = null;
        $this->cachedGroupedSelectableTableRecordKeys = [];
    }

    /**
     * Whether the client's tracking flag is honoured. The flag is untrusted like
     * the two bags, and it is the only one whose forgery *widens* the reach — an
     * inverted selection with an empty exclusion bag means every row — so the
     * gate the client renders its own checkbox behaviour from is re-derived
     * here: a table that cannot track deselections at all, and a query-lane
     * table that confines selection to the current page, resolve a forged flag
     * as no tracking rather than as the whole set. A `records()` table is
     * page-only by construction and is left alone, since its tracking arm
     * resolves off the page collection the user was actually shown.
     */
    private function tracksDeselectedTableRecords(): bool
    {
        if (!$this->isTrackingDeselectedTableRecords) {
            return false;
        }

        $table = $this->getTable();

        if (!$table->canTrackDeselectedRecords()) {
            return false;
        }

        return !$table->hasQuery() || !$table->selectsCurrentPageOnly();
    }

    /**
     * The writable bags are client data: keep only scalar keys, as strings,
     * list-shaped.
     *
     * @param array<mixed> $keys
     *
     * @return list<string>
     */
    private function normalizedSelectedTableRecordKeys(array $keys): array
    {
        return array_values(array_map(
            static fn (mixed $key): string => (string) $key,
            array_filter($keys, static fn (mixed $key): bool => is_scalar($key) && !is_bool($key)),
        ));
    }

    /**
     * Both lanes key through `Group::getStringKey()` — the same reader the group
     * header rendered its key with, so the checkbox and the heading above it
     * cannot disagree. Filament's records lane reads the group column raw
     * instead, which loses `date()`, `getKeyFromRecordUsing()` and every enum.
     *
     * @return list<string>
     */
    private function resolveGroupedSelectableTableRecordKeys(Group $grouping, ?string $group): array
    {
        $table = $this->getTable();

        if ($table->selectsCurrentPageOnly()) {
            $keys = [];

            foreach ($table->getRecords() as $key => $record) {
                if ($grouping->getStringKey($record) !== $group || !$table->isRecordSelectable($record)) {
                    continue;
                }

                $keys[] = (string) $key;
            }

            return $keys;
        }

        $query = $grouping->scopeQueryByKey($this->getFilteredTableQueryOrFail(), $group);

        if (!$table->checksIfRecordIsSelectable()) {
            return $this->pluckTableRecordKeys($query);
        }

        $keys = [];

        /** @var list<object> $records */
        $records = $query->getQuery()->getResult();

        foreach ($records as $record) {
            if (!$table->isRecordSelectable($record)) {
                continue;
            }

            $keys[] = $this->getTableRecordKey($record);
        }

        return $keys;
    }

    /**
     * @param list<object|array<string, mixed>> $records
     *
     * @return list<object|array<string, mixed>>
     */
    private function filterSelectableTableRecords(array $records): array
    {
        $table = $this->getTable();

        if (!$table->checksIfRecordIsSelectable()) {
            return $records;
        }

        return array_values(array_filter(
            $records,
            static fn (object|array $record): bool => $table->isRecordSelectable($record),
        ));
    }

    /**
     * Under `fetchSelectedRecords(false)` the keys are the client's alone, so
     * the selectability callback has to reach the query itself — otherwise a
     * DQL bulk write would touch rows the table refuses to let the user select.
     */
    private function constrainQueryToSelectableTableRecords(QueryBuilder $query): void
    {
        $table = $this->getTable();

        if (!$table->checksIfRecordIsSelectable()) {
            return;
        }

        [$rootAlias, $recordClass] = $this->resolveTableRecordKeyTarget($query);

        $selectableKeys = [];

        /** @var list<object> $records */
        $records = (clone $query)->getQuery()->getResult();

        foreach ($records as $record) {
            if (!$table->isRecordSelectable($record)) {
                continue;
            }

            $selectableKeys[] = $this->getTableRecordKey($record);
        }

        $query->andWhere(IdentifierConstraint::in($query, $rootAlias, $recordClass, $selectableKeys, 'sylament_selectable_record_keys'));
    }

    /**
     * @return list<string>
     */
    private function pluckTableRecordKeys(QueryBuilder $query): array
    {
        [$rootAlias, $recordClass] = $this->resolveTableRecordKeyTarget($query);

        $keyQuery = clone $query;
        $selection = IdentifierConstraint::select($keyQuery, $rootAlias, $recordClass);

        /** @var list<array<string, mixed>> $rows */
        $rows = $keyQuery
            ->select(...$selection->expressions)
            ->getQuery()
            ->getScalarResult();

        return array_values(array_unique(array_map($selection->keyOf(...), $rows)));
    }

    /**
     * Every record the current filters admit, keyed by record key — the
     * hydrating arm `checkIfRecordIsSelectableUsing()` forces, since
     * selectability is a PHP predicate no query can express.
     *
     * @return array<string, object|array<string, mixed>>
     */
    private function getFilteredTableRecordsForSelection(): array
    {
        /** @var list<object> $records */
        $records = $this->getFilteredTableQueryOrFail()->getQuery()->getResult();

        $keyedRecords = [];

        foreach ($records as $record) {
            $keyedRecords[$this->getTableRecordKey($record)] = $record;
        }

        return $keyedRecords;
    }

    private function getFilteredTableQueryOrFail(): QueryBuilder
    {
        return $this->getFilteredTableQuery()
            ?? throw new LogicException(sprintf('Table [%s] resolves selected records from a query it does not have; a records() table resolves them from its page.', static::class));
    }

    /**
     * The root alias and record class every key predicate on this table is built
     * against.
     *
     * @return array{string, class-string}
     */
    private function resolveTableRecordKeyTarget(QueryBuilder $query): array
    {
        $recordClass = $this->getTable()->getRecordClass()
            ?? throw new LogicException(sprintf('Table [%s] must have [query()] to resolve records by key.', static::class));

        $rootAlias = $query->getRootAliases()[0]
            ?? throw new LogicException(sprintf('The query of table [%s] names no root alias to resolve records against.', static::class));

        return [$rootAlias, $recordClass];
    }
}
