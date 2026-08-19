<?php

declare(strict_types=1);

namespace Sylament\Tables\Concerns;

use Closure;
use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\Tools\Pagination\Paginator as DoctrinePaginator;
use LogicException;
use Stringable;
use Sylament\Schemas\Components\Concerns\CanAccessProperties;
use Sylament\Support\ArrayRecord;
use Sylament\Support\Pagination\Paginator;
use Sylament\Tables\Query\AggregateDeclaration;
use Sylament\Tables\Query\AggregateQuery;
use Sylament\Tables\Query\JoinCardinality;
use Sylament\Tables\Table;
use Symfony\Component\PropertyAccess\Exception\AccessException;
use Symfony\UX\LiveComponent\Attribute\LiveProp;

use function Sylament\Support\blank;

trait HasTable
{
    use CanAccessProperties;
    use CanPaginateRecords;
    use CanSearchRecords;
    use CanSortRecords;
    use HasFilters;

    /**
     * The morph-key prefix every row and cell of this host's table is stamped
     * with — a `LiveProp` so the same ids come back on a re-render.
     */
    #[LiveProp]
    public ?string $tableHostId = null;

    protected ?Table $cachedTable = null;

    /**
     * @var array<array-key, object|array<string, mixed>>|Paginator|null
     */
    protected array|Paginator|null $cachedTableRecords = null;

    public function table(Table $table): Table
    {
        return $table;
    }

    public function getTableHostId(): string
    {
        return $this->tableHostId ??= bin2hex(random_bytes(4));
    }

    public function getTable(): Table
    {
        return $this->cachedTable ??= $this->table($this->makeTable());
    }

    protected function makeTable(): Table
    {
        return Table::make($this);
    }

    public function getFilteredTableQuery(): ?QueryBuilder
    {
        $table = $this->getTable();

        if (!$table->hasQuery()) {
            return null;
        }

        $query = $table->getQuery();

        if (!$query instanceof QueryBuilder) {
            return null;
        }

        return $this->filterTableQuery($query);
    }

    public function filterTableQuery(QueryBuilder $query): QueryBuilder
    {
        return $this->applySearchToTableQuery($this->applyFiltersToTableQuery($query));
    }

    public function getFilteredSortedTableQuery(): ?QueryBuilder
    {
        $query = $this->getFilteredTableQuery();

        if (!$query instanceof QueryBuilder) {
            return null;
        }

        return $this->applySortingToTableQuery($query);
    }

    /**
     * @return array<array-key, object|array<string, mixed>>|Paginator keyed by record key
     */
    public function getTableRecords(): array|Paginator
    {
        return $this->cachedTableRecords ??= $this->resolveTableRecords();
    }

    public function getTablePaginator(): ?Paginator
    {
        $records = $this->getTableRecords();

        return $records instanceof Paginator ? $records : null;
    }

    /**
     * @return object|array<string, mixed>|null
     */
    public function getTableRecord(?string $key): object|array|null
    {
        if ($key === null) {
            return null;
        }

        if ($this->getTable()->hasQuery()) {
            return $this->resolveQueryTableRecord($key);
        }

        $records = $this->cachedTableRecords ?? $this->resolveTableRecords();

        if ($records instanceof Paginator) {
            $records = $records->getRecords();
        }

        return $records[$key] ?? null;
    }

    public function flushCachedTableRecords(): void
    {
        $this->cachedTableRecords = null;
    }

    /**
     * @param object|array<string, mixed> $record
     */
    public function getTableRecordKey(object|array $record): string
    {
        if (is_array($record)) {
            return self::toRecordKey($record[ArrayRecord::getKeyName()] ?? null)
                ?? throw new LogicException(sprintf('Record arrays must have a unique, printable [%s] entry for identification.', ArrayRecord::getKeyName()));
        }

        $queryRecordKey = $this->resolveQueryRecordKey($record);

        if ($queryRecordKey !== null) {
            return $queryRecordKey;
        }

        try {
            $key = $this->getPropertyAccessor()->getFromRecord($record, 'id');
        } catch (AccessException) {
            $key = null;
        }

        return self::toRecordKey($key)
            ?? throw new LogicException(sprintf('Object records must expose a readable non-blank [id] for identification; [%s] does not. Give it one, or use array records with a [%s] entry.', get_debug_type($record), ArrayRecord::getKeyName()));
    }

    /**
     * The printable form of a record key, or null when the value cannot
     * identify a row — blank, or neither scalar nor `Stringable`.
     */
    private static function toRecordKey(mixed $key): ?string
    {
        if ($key instanceof Stringable) {
            $key = (string) $key;
        }

        if (blank($key) || !is_scalar($key)) {
            return null;
        }

        return (string) $key;
    }

    public function getAllTableRecordsCount(): int
    {
        if ($this->getTable()->hasQuery()) {
            $cachedTotal = $this->cachedTableRecords instanceof Paginator
                ? $this->cachedTableRecords->getTotalRecordCount()
                : null;

            if ($cachedTotal !== null) {
                return $cachedTotal;
            }

            $query = $this->getFilteredTableQuery();

            if ($query instanceof QueryBuilder) {
                return new DoctrinePaginator($query->getQuery(), JoinCardinality::joinsCollection($query))->count();
            }
        }

        $records = $this->getTableRecords();

        if ($records instanceof Paginator) {
            return $records->getTotalRecordCount()
                ?? throw new LogicException(sprintf('Table [%s] cannot count its records: a simple-paginated records() closure carries no total.', static::class));
        }

        return count($records);
    }

    /**
     * @return array<array-key, object|array<string, mixed>>|Paginator keyed by record key
     */
    protected function resolveTableRecords(): array|Paginator
    {
        $table = $this->getTable();
        $dataSource = $table->getDataSource();

        if ($dataSource instanceof Closure) {
            return $this->keyDataSourceRecords($table->evaluate($dataSource, [
                'filters' => fn (): array => $this->tableFilters,
                'page' => fn (): int => $this->getTablePage(),
                'recordsPerPage' => fn (): int|string|null => $this->getTableRecordsPerPage(),
                'search' => fn (): ?string => $this->getTableSearch(),
                'sort' => fn (): array => [$this->getTableSortColumn(), $this->getTableSortDirection()],
                'sortColumn' => fn (): ?string => $this->getTableSortColumn(),
                'sortDirection' => fn (): ?string => $this->getTableSortDirection(),
            ]));
        }

        $query = $this->getFilteredSortedTableQuery();

        if (!$query instanceof QueryBuilder) {
            throw new LogicException(sprintf('Table [%s] must have [records()] or [query()].', static::class));
        }

        foreach ($table->getColumns() as $column) {
            $column->applyEagerLoading($query);
        }

        if (!$table->isPaginated()) {
            /** @var list<object|array<string, mixed>> $records */
            $records = $query->getQuery()->getResult();

            return $this->keyTableRecords($records);
        }

        return $this->paginateTableQuery($query);
    }

    /**
     * The raw return of a `records()` closure — `mixed` because nothing
     * constrains it.
     *
     * @return array<array-key, object|array<string, mixed>>|Paginator keyed by record key
     */
    private function keyDataSourceRecords(mixed $records): array|Paginator
    {
        if ($records instanceof Paginator) {
            return $records->withRecords($this->keyTableRecords($records->getRecords()));
        }

        if (!is_iterable($records)) {
            throw new LogicException(sprintf('The [records()] closure of table [%s] must return an iterable or a [%s], got %s.', static::class, Paginator::class, get_debug_type($records)));
        }

        return $this->keyTableRecords($records);
    }

    /**
     * @param iterable<mixed, mixed> $records rows a records() closure, a paginator
     *                                        or the query lane produced
     *
     * @return array<array-key, object|array<string, mixed>> keyed by record key
     */
    private function keyTableRecords(iterable $records): array
    {
        $keyedRecords = [];

        foreach ($records as $sourceKey => $record) {
            if (is_array($record)) {
                if (!self::isFieldKeyedRecord($record)) {
                    throw new LogicException(sprintf('Array records of table [%s] must be keyed by field name; one arrived carrying a non-string key.', static::class));
                }

                $key = self::toRecordKey($record[ArrayRecord::getKeyName()] ?? $sourceKey)
                    ?? throw new LogicException(sprintf('Record arrays must have a unique, printable [%s] entry for identification.', ArrayRecord::getKeyName()));

                $record[ArrayRecord::getKeyName()] = $key;

                $keyedRecords[$key] = $record;

                continue;
            }

            if (!is_object($record)) {
                throw new LogicException(sprintf('Records of table [%s] must be objects or field-keyed arrays, got %s.', static::class, get_debug_type($record)));
            }

            $keyedRecords[$this->getTableRecordKey($record)] = $record;
        }

        $this->fillTableAggregateValues(array_map(static fn (int|string $key): string => (string) $key, array_keys($keyedRecords)));

        return $keyedRecords;
    }

    /**
     * An array record is a field-keyed map — that is what every column read and
     * the `__key` entry rely on, so an array carrying any other key is not one.
     *
     * @param array<mixed, mixed> $record
     *
     * @phpstan-assert-if-true array<string, mixed> $record
     */
    private static function isFieldKeyedRecord(array $record): bool
    {
        return array_all(array_keys($record), static fn (int|string $field): bool => is_string($field));
    }

    /**
     * One batched read per aggregate column, over the keys of the page just
     * resolved, landing in the table's side store for the cells to read — so
     * the page query stays a plain entity read. A no-op off the query lane,
     * where there is no entity manager to batch against, and asked before the
     * declared query is reached at all, since a records closure wins over one
     * that must then stay unevaluated.
     *
     * @param list<string> $keys
     */
    protected function fillTableAggregateValues(array $keys): void
    {
        $table = $this->getTable();

        if (!$table->hasQuery()) {
            return;
        }

        $entityManager = $table->getQueryEntityManager();
        $recordClass = $table->getRecordClass();

        if ($entityManager === null || $recordClass === null || $keys === []) {
            return;
        }

        foreach ($table->getColumns() as $column) {
            $declaration = $column->getCellAggregateDeclaration();

            if (!$declaration instanceof AggregateDeclaration) {
                continue;
            }

            $scope = $declaration->scope;

            $fetched = AggregateQuery::fetch($entityManager, $recordClass, new AggregateDeclaration(
                function: $declaration->function,
                relationshipPath: $declaration->relationshipPath,
                column: $declaration->column,
                scope: $scope instanceof Closure ? fn (QueryBuilder $query, string $alias): mixed => $column->evaluate($scope, ['alias' => $alias, 'query' => $query]) : null,
                castToBool: $declaration->castToBool,
            ), $keys);

            $default = match (true) {
                $declaration->castToBool => false,
                $declaration->function === 'COUNT' => 0,
                default => null,
            };

            $values = [];

            foreach ($keys as $key) {
                $value = $fetched[$key] ?? $default;

                $values[$key] = $declaration->castToBool ? ((int) $value > 0) : $value;
            }

            $table->setAggregateValues($column->getName(), $values);
        }
    }

    private function resolveQueryTableRecord(string $key): ?object
    {
        $table = $this->getTable();
        $query = $table->getQuery(isResolvingRecord: true);
        $entityManager = $table->getQueryEntityManager();
        $recordClass = $table->getRecordClass();

        if (!$query instanceof QueryBuilder || $entityManager === null || $recordClass === null) {
            throw new LogicException(sprintf('Table [%s] must have [records()] or [query()].', static::class));
        }

        $metadata = $entityManager->getClassMetadata($recordClass);

        if ($metadata->isIdentifierComposite) {
            throw new LogicException(sprintf('Records of [%s] carry a composite identifier, which a table cannot key; use single-identifier entities or the records() lane.', $recordClass));
        }

        $rootAlias = $query->getRootAliases()[0]
            ?? throw new LogicException(sprintf('The query of table [%s] names no root alias to resolve a record against.', static::class));

        $record = $this->applyFiltersToTableQuery($query, isResolvingRecord: true)
            ->andWhere(sprintf('%s.%s = :sylament_record_key', $rootAlias, $metadata->getSingleIdentifierFieldName()))
            ->setParameter('sylament_record_key', $key)
            ->getQuery()
            ->getOneOrNullResult();

        return is_object($record) ? $record : null;
    }

    private function resolveQueryRecordKey(object $record): ?string
    {
        $table = $this->getTable();

        if (!$table->hasQuery()) {
            return null;
        }

        $recordClass = $table->getRecordClass();
        $entityManager = $table->getQueryEntityManager();

        if ($recordClass === null || $entityManager === null || !$record instanceof $recordClass) {
            return null;
        }

        $metadata = $entityManager->getClassMetadata($recordClass);

        if ($metadata->isIdentifierComposite) {
            throw new LogicException(sprintf('Records of [%s] carry a composite identifier, which a table cannot key; use single-identifier entities or the records() lane.', $record::class));
        }

        $identifierValues = $metadata->getIdentifierValues($record);

        if ($identifierValues === []) {
            throw new LogicException(sprintf('Object records must expose a readable non-blank identifier; [%s] has none set.', $record::class));
        }

        return self::toRecordKey(array_first($identifierValues))
            ?? throw new LogicException(sprintf('The identifier of [%s] is not a printable value.', $record::class));
    }
}
