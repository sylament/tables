<?php

declare(strict_types=1);

namespace Sylament\Tables\Concerns;

use LogicException;
use Sylament\Tables\Table;
use Traversable;

trait HasTable
{
    protected ?Table $cachedTable = null;

    /**
     * @var list<object|array<string, mixed>>|null
     */
    protected ?array $cachedTableRecords = null;

    public function table(Table $table): Table
    {
        return $table;
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
     * @return list<object|array<string, mixed>>
     */
    public function getTableRecords(): array
    {
        if ($this->cachedTableRecords !== null) {
            return $this->cachedTableRecords;
        }

        $table = $this->getTable();
        $dataSource = $table->getDataSource();

        if ($dataSource === null) {
            throw new LogicException(sprintf('Table [%s] must have [records()].', static::class));
        }

        $records = $table->evaluate($dataSource);

        if (!is_iterable($records)) {
            throw new LogicException(sprintf('The [records()] closure of table [%s] must return an iterable, got %s.', static::class, get_debug_type($records)));
        }

        /** @var iterable<object|array<string, mixed>> $records */
        return $this->cachedTableRecords = array_values($records instanceof Traversable ? iterator_to_array($records, preserve_keys: false) : $records);
    }
}
