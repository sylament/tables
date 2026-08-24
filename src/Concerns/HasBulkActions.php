<?php

declare(strict_types=1);

namespace Sylament\Tables\Concerns;

use Doctrine\ORM\QueryBuilder;
use LogicException;
use Stringable;
use Sylament\Support\Pagination\Paginator;
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

        $query = $this->getSelectedTableRecordsQuery($shouldFetchSelectedRecords);

        if (!$shouldFetchSelectedRecords) {
            return $this->cachedSelectedTableRecords = $this->pluckTableRecordKeys($query);
        }

        /** @var list<object> $records */
        $records = $query->getQuery()->getResult();

        return $this->cachedSelectedTableRecords = $this->filterSelectableTableRecords($records);
    }

    /**
     * The table's query narrowed to the selection — `IN` the selected keys, or
     * `NOT IN` the deselected ones while tracking. The filters are re-applied
     * only when the table deselects on filtering, which is what makes the
     * surviving selection provably inside them; without that coupling the
     * selection deliberately outlives the filters and must not be constrained.
     */
    public function getSelectedTableRecordsQuery(bool $shouldFetchSelectedRecords = true): QueryBuilder
    {
        $table = $this->getTable();
        $query = $table->getQuery();

        if (!$query instanceof QueryBuilder) {
            throw new LogicException(sprintf('Table [%s] resolves selected records from a query it does not have; a records() table resolves them from its page.', static::class));
        }

        $identifier = $this->resolveTableRecordKeyExpression($query);

        if ($this->tracksDeselectedTableRecords()) {
            $deselectedKeys = $this->normalizedSelectedTableRecordKeys($this->deselectedTableRecords);

            // An empty exclusion set means every row, so the constraint is
            // omitted rather than emitted: Doctrine expands an empty list
            // parameter to NULL, and `NOT IN (NULL)` matches nothing.
            if ($deselectedKeys !== []) {
                $query
                    ->andWhere(sprintf('%s NOT IN (:sylament_selected_record_keys)', $identifier))
                    ->setParameter('sylament_selected_record_keys', $deselectedKeys);
            }
        } else {
            $query
                ->andWhere(sprintf('%s IN (:sylament_selected_record_keys)', $identifier))
                ->setParameter('sylament_selected_record_keys', $this->normalizedSelectedTableRecordKeys($this->selectedTableRecordKeys));
        }

        if ($shouldFetchSelectedRecords) {
            foreach ($table->getColumns() as $column) {
                $column->applyEagerLoading($query);
            }
        }

        if ($table->shouldDeselectAllRecordsWhenFiltered()) {
            $this->filterTableQuery($query);
        }

        if (!$shouldFetchSelectedRecords) {
            $this->constrainQueryToSelectableTableRecords($query, $identifier);
        }

        return $query;
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
    private function constrainQueryToSelectableTableRecords(QueryBuilder $query, string $identifier): void
    {
        $table = $this->getTable();

        if (!$table->checksIfRecordIsSelectable()) {
            return;
        }

        $selectableKeys = [];

        /** @var list<object> $records */
        $records = (clone $query)->getQuery()->getResult();

        foreach ($records as $record) {
            if (!$table->isRecordSelectable($record)) {
                continue;
            }

            $selectableKeys[] = $this->getTableRecordKey($record);
        }

        $query
            ->andWhere(sprintf('%s IN (:sylament_selectable_record_keys)', $identifier))
            ->setParameter('sylament_selectable_record_keys', $selectableKeys);
    }

    /**
     * @return list<string>
     */
    private function pluckTableRecordKeys(QueryBuilder $query): array
    {
        /** @var list<mixed> $keys */
        $keys = (clone $query)
            ->select($this->resolveTableRecordKeyExpression($query))
            ->getQuery()
            ->getSingleColumnResult();

        return array_values(array_unique(array_map(
            static function (mixed $key): string {
                if ($key instanceof Stringable) {
                    $key = (string) $key;
                }

                if (!is_scalar($key)) {
                    throw new LogicException(sprintf('A selected record key came back as [%s], which cannot identify a row.', get_debug_type($key)));
                }

                return (string) $key;
            },
            $keys,
        )));
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
     * The qualified identifier of the table's records — `alias.field`.
     */
    private function resolveTableRecordKeyExpression(QueryBuilder $query): string
    {
        $table = $this->getTable();
        $entityManager = $table->getQueryEntityManager();
        $recordClass = $table->getRecordClass();

        if ($entityManager === null || $recordClass === null) {
            throw new LogicException(sprintf('Table [%s] must have [query()] to resolve records by key.', static::class));
        }

        $metadata = $entityManager->getClassMetadata($recordClass);

        if ($metadata->isIdentifierComposite) {
            throw new LogicException(sprintf('Records of [%s] carry a composite identifier, which a table cannot key; use single-identifier entities or the records() lane.', $recordClass));
        }

        $rootAlias = $query->getRootAliases()[0]
            ?? throw new LogicException(sprintf('The query of table [%s] names no root alias to resolve records against.', static::class));

        return sprintf('%s.%s', $rootAlias, $metadata->getSingleIdentifierFieldName());
    }
}
