<?php

declare(strict_types=1);

namespace Sylament\Tables\Filters\Concerns;

use LogicException;
use Sylament\Tables\Concerns\HasTable;
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

    /**
     * The applied state — what the query layer filters on.
     *
     * @return array<string, mixed>
     */
    public function getState(): array
    {
        $liveComponent = $this->getLiveComponent();

        if (!method_exists($liveComponent, 'getTableFilterState')) {
            throw new LogicException(sprintf('Table host [%s] provides no filter state: it must compose [%s].', $liveComponent::class, HasTable::class));
        }

        return $this->normalizeFilterState($liveComponent->getTableFilterState($this->getName()));
    }

    /**
     * The form-bound state — the draft bag while the table defers its filters.
     *
     * @return array<string, mixed>
     */
    public function getFormState(): array
    {
        $liveComponent = $this->getLiveComponent();

        if (!method_exists($liveComponent, 'getTableFilterFormState')) {
            throw new LogicException(sprintf('Table host [%s] provides no filter state: it must compose [%s].', $liveComponent::class, HasTable::class));
        }

        return $this->normalizeFilterState($liveComponent->getTableFilterFormState($this->getName()));
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeFilterState(mixed $state): array
    {
        if (!is_array($state)) {
            return [];
        }

        $normalized = [];

        foreach ($state as $key => $value) {
            $normalized[(string) $key] = $value;
        }

        return $normalized;
    }
}
