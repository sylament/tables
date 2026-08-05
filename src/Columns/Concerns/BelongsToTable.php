<?php

declare(strict_types=1);

namespace Sylament\Tables\Columns\Concerns;

use Sylament\Tables\Contracts\InteractsWithTable;
use Sylament\Tables\Table;

trait BelongsToTable
{
    protected Table $table;

    public function table(Table $table): static
    {
        $this->table = $table;

        return $this;
    }

    public function getTable(): Table
    {
        return $this->table;
    }

    public function getLiveComponent(): InteractsWithTable
    {
        return $this->getTable()->getLiveComponent();
    }
}
