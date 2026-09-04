<?php

declare(strict_types=1);

namespace Sylament\Tables\Grouping\Concerns;

use LogicException;
use Sylament\Tables\Contracts\InteractsWithTable;
use Sylament\Tables\Table;

trait BelongsToTable
{
    protected ?Table $table = null;

    public function table(Table $table): static
    {
        $this->table = $table;

        return $this;
    }

    public function getTable(): Table
    {
        return $this->table ?? throw new LogicException(sprintf('The group [%s] is not mounted to a table.', $this->getId()));
    }

    public function getLiveComponent(): InteractsWithTable
    {
        return $this->getTable()->getLiveComponent();
    }
}
