<?php

declare(strict_types=1);

namespace Sylament\Tables\Table\Concerns;

use Closure;

trait CanPaginateRecords
{
    protected int|string|Closure|null $defaultPaginationPageOption = null;

    protected bool|Closure $isPaginated = true;

    /**
     * @var array<int|string>|Closure|null
     */
    protected array|Closure|null $paginationPageOptions = null;

    protected bool|Closure $hasExtremePaginationLinks = false;

    public function defaultPaginationPageOption(int|string|Closure|null $option): static
    {
        $this->defaultPaginationPageOption = $option;

        return $this;
    }

    /**
     * @param bool|array<int|string>|Closure $condition
     */
    public function paginated(bool|array|Closure $condition = true): static
    {
        if (is_array($condition)) {
            $this->paginationPageOptions($condition);
            $condition = true;
        }

        $this->isPaginated = $condition;

        return $this;
    }

    /**
     * @param array<int|string>|Closure|null $options
     */
    public function paginationPageOptions(array|Closure|null $options): static
    {
        $this->paginationPageOptions = $options;

        return $this;
    }

    public function extremePaginationLinks(bool|Closure $condition = true): static
    {
        $this->hasExtremePaginationLinks = $condition;

        return $this;
    }

    public function getDefaultPaginationPageOption(): int|string|null
    {
        $option = $this->evaluate($this->defaultPaginationPageOption);

        if ($option) {
            return $option;
        }

        $options = $this->getPaginationPageOptions();

        if (in_array(10, $options, true)) {
            return 10;
        }

        return $options === [] ? null : array_first($options);
    }

    /**
     * @return array<int|string>
     */
    public function getPaginationPageOptions(): array
    {
        return $this->evaluate($this->paginationPageOptions) ?? [5, 10, 25, 50];
    }

    public function isPaginated(): bool
    {
        return (bool) $this->evaluate($this->isPaginated);
    }

    public function hasExtremePaginationLinks(): bool
    {
        return (bool) $this->evaluate($this->hasExtremePaginationLinks);
    }
}
