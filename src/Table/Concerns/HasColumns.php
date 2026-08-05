<?php

declare(strict_types=1);

namespace Sylament\Tables\Table\Concerns;

use Sylament\Tables\Columns\Column;

trait HasColumns
{
    /**
     * @var array<string, Column>
     */
    protected array $columns = [];

    /**
     * @param array<Column> $columns
     */
    public function columns(array $columns): static
    {
        $this->columns = [];

        foreach ($columns as $column) {
            $column->table($this);

            $this->columns[$column->getName()] = $column;
        }

        return $this;
    }

    /**
     * @return array<string, Column>
     */
    public function getColumns(): array
    {
        return $this->columns;
    }

    public function getColumn(string $name): ?Column
    {
        return $this->getColumns()[$name] ?? null;
    }
}
