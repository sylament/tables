<?php

declare(strict_types=1);

namespace Sylament\Tables\Concerns;

use LogicException;
use Stringable;
use Sylament\Schemas\Components\Concerns\CanAccessProperties;
use Sylament\Support\ArrayRecord;
use Sylament\Tables\Table;
use Symfony\Component\PropertyAccess\Exception\AccessException;
use Symfony\UX\LiveComponent\Attribute\LiveProp;

use function Sylament\Support\blank;

trait HasTable
{
    use CanAccessProperties;

    /**
     * The morph-key prefix every row and cell of this host's table is stamped
     * with — a `LiveProp` so the same ids come back on a re-render.
     */
    #[LiveProp]
    public ?string $tableHostId = null;

    protected ?Table $cachedTable = null;

    /**
     * @var array<array-key, object|array<string, mixed>>|null
     */
    protected ?array $cachedTableRecords = null;

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
     * @return array<array-key, object|array<string, mixed>> keyed by record key
     */
    public function getTableRecords(): array
    {
        return $this->cachedTableRecords ??= $this->resolveTableRecords();
    }

    /**
     * @return object|array<string, mixed>|null
     */
    public function getTableRecord(?string $key): object|array|null
    {
        if ($key === null) {
            return null;
        }

        return ($this->cachedTableRecords ?? $this->resolveTableRecords())[$key] ?? null;
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
     * @return array<array-key, object|array<string, mixed>> keyed by record key
     */
    protected function resolveTableRecords(): array
    {
        $table = $this->getTable();
        $dataSource = $table->getDataSource();

        if ($dataSource === null) {
            throw new LogicException(sprintf('Table [%s] must have [records()].', static::class));
        }

        $records = $table->evaluate($dataSource);

        if (!is_iterable($records)) {
            throw new LogicException(sprintf('The [records()] closure of table [%s] must return an iterable, got %s.', static::class, get_debug_type($records)));
        }

        $keyedRecords = [];

        /** @var iterable<array-key, object|array<string, mixed>> $records */
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
