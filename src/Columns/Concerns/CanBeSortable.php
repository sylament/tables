<?php

declare(strict_types=1);

namespace Sylament\Tables\Columns\Concerns;

use Closure;

trait CanBeSortable
{
    protected bool|Closure $isSortable = false;

    /**
     * @var array<string>|null
     */
    protected ?array $sortColumns = [];

    protected ?Closure $sortQuery = null;

    /**
     * @param bool|array<string>|Closure $condition
     */
    public function sortable(bool|array|Closure $condition = true, ?Closure $query = null): static
    {
        if (is_array($condition)) {
            $this->isSortable = true;
            $this->sortColumns = $condition;
        } else {
            $this->isSortable = $condition;
            $this->sortColumns = null;
        }

        $this->sortQuery = $query;

        return $this;
    }

    /**
     * @return array<string>
     */
    public function getSortColumns(): array
    {
        return $this->sortColumns ?? [$this->getName()];
    }

    public function isSortable(): bool
    {
        return (bool) $this->evaluate($this->isSortable);
    }
}
