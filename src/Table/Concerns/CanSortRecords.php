<?php

declare(strict_types=1);

namespace Sylament\Tables\Table\Concerns;

use Sylament\Tables\Columns\Column;

trait CanSortRecords
{
    public function getSortableVisibleColumn(string $name): ?Column
    {
        $column = $this->getColumn($name);

        if (!$column instanceof Column) {
            return null;
        }

        if (!$column->isSortable()) {
            return null;
        }

        return $column;
    }

    public function getSortColumn(): ?string
    {
        return $this->getLiveComponent()->getTableSortColumn();
    }

    public function getSortDirection(): ?string
    {
        return $this->getLiveComponent()->getTableSortDirection();
    }
}
