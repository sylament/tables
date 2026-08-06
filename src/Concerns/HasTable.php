<?php

declare(strict_types=1);

namespace Sylament\Tables\Concerns;

use LogicException;
use Stringable;
use Sylament\Schemas\Components\Concerns\CanAccessProperties;
use Sylament\Support\ArrayRecord;
use Sylament\Support\Pagination\Paginator;
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
            $key = $record[ArrayRecord::getKeyName()]
                ?? throw new LogicException(sprintf('Record arrays must have a unique [%s] entry for identification.', ArrayRecord::getKeyName()));

            return (string) $key;
        }

        try {
            $key = $this->getPropertyAccessor()->getFromRecord($record, 'id');
        } catch (AccessException) {
            $key = null;
        }

        if ($key instanceof Stringable) {
            $key = (string) $key;
        }

        if (!is_scalar($key) || blank($key)) {
            throw new LogicException(sprintf('Object records must expose a readable non-blank [id] for identification; [%s] does not. Give it one, or use array records with a [%s] entry.', get_debug_type($record), ArrayRecord::getKeyName()));
        }

        return (string) $key;
    }

    /**
     * @return array<array-key, object|array<string, mixed>>|Paginator keyed by record key
     */
    protected function resolveTableRecords(): array|Paginator
    {
        $table = $this->getTable();
        $dataSource = $table->getDataSource();

        if ($dataSource === null) {
            throw new LogicException(sprintf('Table [%s] must have [records()].', static::class));
        }

        $records = $table->evaluate($dataSource, [
            'page' => fn (): int => $this->getTablePage(),
            'recordsPerPage' => fn (): int|string|null => $this->getTableRecordsPerPage(),
            'search' => fn (): ?string => $this->getTableSearch(),
            'sort' => fn (): array => [$this->getTableSortColumn(), $this->getTableSortDirection()],
            'sortColumn' => fn (): ?string => $this->getTableSortColumn(),
            'sortDirection' => fn (): ?string => $this->getTableSortDirection(),
        ]);

        if ($records instanceof Paginator) {
            return $records->withRecords($this->keyTableRecords($records->getRecords()));
        }

        if (!is_iterable($records)) {
            throw new LogicException(sprintf('The [records()] closure of table [%s] must return an iterable or a [%s], got %s.', static::class, Paginator::class, get_debug_type($records)));
        }

        return $this->keyTableRecords($records);
    }

    /**
     * @param iterable<array-key, object|array<string, mixed>> $records
     *
     * @return array<array-key, object|array<string, mixed>> keyed by record key
     */
    private function keyTableRecords(iterable $records): array
    {
        $keyedRecords = [];

        foreach ($records as $sourceKey => $record) {
            if (is_array($record)) {
                $record[ArrayRecord::getKeyName()] ??= $sourceKey;
                $record[ArrayRecord::getKeyName()] = (string) $record[ArrayRecord::getKeyName()];

                $keyedRecords[$record[ArrayRecord::getKeyName()]] = $record;

                continue;
            }

            $keyedRecords[$this->getTableRecordKey($record)] = $record;
        }

        return $keyedRecords;
    }
}
