<?php

declare(strict_types=1);

namespace Sylament\Tables\Columns\Concerns;

use Closure;

use function Sylament\Support\wrap;

trait CanBeSearchable
{
    protected bool $isGloballySearchable = false;

    protected bool $isIndividuallySearchable = false;

    protected bool|Closure $isSearchable = false;

    /**
     * @var array<string>|null
     */
    protected ?array $searchColumns = null;

    protected ?Closure $searchQuery = null;

    /**
     * @param bool|array<string>|string|Closure $condition
     */
    public function searchable(
        bool|array|string|Closure $condition = true,
        ?Closure $query = null,
        bool $isIndividual = false,
        bool $isGlobal = true,
    ): static {
        if (is_bool($condition) || $condition instanceof Closure) {
            $this->isSearchable = $condition;
            $this->searchColumns = null;
        } else {
            $this->isSearchable = true;
            $this->searchColumns = wrap($condition);
        }

        $this->isGloballySearchable = $isGlobal;
        $this->isIndividuallySearchable = $isIndividual;
        $this->searchQuery = $query;

        return $this;
    }

    public function isSearchable(): bool
    {
        return (bool) $this->evaluate($this->isSearchable);
    }

    public function isGloballySearchable(): bool
    {
        return $this->isSearchable() && $this->isGloballySearchable;
    }

    public function isIndividuallySearchable(): bool
    {
        return $this->isSearchable() && $this->isIndividuallySearchable;
    }
}
