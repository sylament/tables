<?php

declare(strict_types=1);

namespace Sylament\Tables\Columns\Summarizers\Concerns;

use LogicException;
use Sylament\Tables\Columns\Column;
use Sylament\Tables\Contracts\InteractsWithTable;
use Sylament\Tables\Table;

trait BelongsToColumn
{
    protected ?Column $column = null;

    public function column(Column $column): static
    {
        $this->column = $column;

        return $this;
    }

    public function getColumn(): Column
    {
        return $this->column ?? throw new LogicException(sprintf('The summarizer [%s] is not attached to a column.', static::class));
    }

    public function getTable(): Table
    {
        return $this->getColumn()->getTable();
    }

    public function getLiveComponent(): InteractsWithTable
    {
        return $this->getTable()->getLiveComponent();
    }
}
