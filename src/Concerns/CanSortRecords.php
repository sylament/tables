<?php

declare(strict_types=1);

namespace Sylament\Tables\Concerns;

use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;

use function Sylament\Support\blank;

trait CanSortRecords
{
    /**
     * `column:direction`, or a bare column meaning ascending; null when
     * unsorted. Mutated only by `sortTable()`, so it stays checksummed.
     */
    #[LiveProp]
    public ?string $tableSort = null;

    /**
     * A request naming a column that is not declared sortable is ignored, so
     * the `sortColumn` injection only ever carries declared-sortable names.
     */
    #[LiveAction]
    public function sortTable(#[LiveArg] string|int|null $column = null, #[LiveArg] ?string $direction = null): void
    {
        $column = $column === null ? null : (string) $column;

        if ($column === null || $this->getTable()->getSortableVisibleColumn($column) === null) {
            return;
        }

        if (!in_array($direction, ['asc', 'desc', null], true)) {
            return;
        }

        if ($column === $this->getTableSortColumn()) {
            $direction ??= match ($this->getTableSortDirection()) {
                'asc' => 'desc',
                'desc' => null,
                default => 'asc',
            };
        } else {
            $direction ??= 'asc';
        }

        $this->tableSort = $direction === null ? null : "{$column}:{$direction}";
        $this->tablePage = 1;
        $this->flushCachedTableRecords();
    }

    public function getTableSortColumn(): ?string
    {
        if (blank($this->tableSort)) {
            return null;
        }

        return explode(':', (string) $this->tableSort, 2)[0];
    }

    public function getTableSortDirection(): ?string
    {
        if (blank($this->tableSort)) {
            return null;
        }

        $parts = explode(':', (string) $this->tableSort, 2);

        if (!isset($parts[1])) {
            return 'asc';
        }

        return match ($parts[1]) {
            'asc', 'desc' => $parts[1],
            default => null,
        };
    }
}
