<?php

declare(strict_types=1);

namespace Sylament\Tables\Table\Concerns;

use Closure;

trait HasRecords
{
    protected ?Closure $dataSource = null;

    public function records(?Closure $dataSource): static
    {
        $this->dataSource = $dataSource;

        return $this;
    }

    public function getDataSource(): ?Closure
    {
        return $this->dataSource;
    }

    /**
     * @return list<object|array<string, mixed>>
     */
    public function getRecords(): array
    {
        return $this->getLiveComponent()->getTableRecords();
    }
}
