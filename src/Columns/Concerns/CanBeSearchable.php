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

    protected bool|Closure|null $isSearchForcedCaseInsensitive = null;

    protected bool|Closure|null $shouldSplitIndividualSearchTerms = null;

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

    public function forceSearchCaseInsensitive(bool|Closure|null $condition = true): static
    {
        $this->isSearchForcedCaseInsensitive = $condition;

        return $this;
    }

    /**
     * @return array<string>
     */
    public function getSearchColumns(): array
    {
        return $this->searchColumns ?? [$this->getName()];
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

    public function isSearchForcedCaseInsensitive(): ?bool
    {
        return $this->evaluate($this->isSearchForcedCaseInsensitive);
    }

    public function splitIndividualSearchTerms(bool|Closure|null $condition = true): static
    {
        $this->shouldSplitIndividualSearchTerms = $condition;

        return $this;
    }

    /**
     * Null defers to the table's own `splitSearchTerms()`.
     */
    public function shouldSplitIndividualSearchTerms(): ?bool
    {
        return $this->evaluate($this->shouldSplitIndividualSearchTerms);
    }
}
