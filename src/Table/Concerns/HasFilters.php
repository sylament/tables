<?php

declare(strict_types=1);

namespace Sylament\Tables\Table\Concerns;

use Closure;
use LogicException;
use Sylament\Schemas\Components\Group;
use Sylament\Tables\Enums\FiltersLayout;
use Sylament\Tables\Enums\FiltersResetActionPosition;
use Sylament\Tables\Filters\BaseFilter;

trait HasFilters
{
    /**
     * @var array<string, BaseFilter>
     */
    protected array $filters = [];

    /**
     * @var array<string, int|null>|int|Closure|null
     */
    protected array|int|Closure|null $filtersFormColumns = null;

    protected string|Closure|null $filtersFormMaxHeight = null;

    protected string|Closure|null $filtersFormWidth = null;

    protected FiltersLayout|Closure|null $filtersLayout = null;

    protected FiltersResetActionPosition|Closure|null $filtersResetActionPosition = null;

    protected bool|Closure $hasDeferredFilters = true;

    protected bool|Closure $shouldDeselectAllRecordsWhenFiltered = true;

    /**
     * @param array<BaseFilter> $filters
     */
    public function filters(array $filters, FiltersLayout|Closure|null $layout = null): static
    {
        $this->filters = [];

        $this->pushFilters($filters);

        if ($layout !== null) {
            $this->filtersLayout($layout);
        }

        return $this;
    }

    /**
     * @param array<BaseFilter> $filters
     */
    public function pushFilters(array $filters): static
    {
        foreach ($filters as $filter) {
            $filter->table($this);

            $this->filters[$filter->getName()] = $filter;
        }

        return $this;
    }

    /**
     * @return array<string, BaseFilter>
     */
    public function getFilters(bool $withHidden = false): array
    {
        if ($withHidden) {
            return $this->filters;
        }

        return array_filter($this->filters, static fn (BaseFilter $filter): bool => $filter->isVisible());
    }

    public function getFilter(string $name, bool $withHidden = false): ?BaseFilter
    {
        return $this->getFilters($withHidden)[$name] ?? null;
    }

    /**
     * One `Group` per visible filter, keyed and state-pathed by the filter name
     * so the host's filters form binds each filter's fields under its own key.
     * The span, start and column values are forwarded raw — the Group evaluates
     * them.
     *
     * @return array<string, Group>
     */
    public function getFiltersFormSchema(): array
    {
        $groups = [];

        foreach ($this->getFilters() as $filterName => $filter) {
            $group = Group::make($filter->getSchemaComponents())
                ->statePath($filterName)
                ->key($filterName)
                ->columnSpan($filter->getColumnSpan());

            if ($filter->getColumnStart() !== null) {
                $group->columnStart($filter->getColumnStart());
            }

            if ($filter->getColumns() !== null) {
                $group->columns($filter->getColumns());
            }

            $groups[$filterName] = $group;
        }

        return $groups;
    }

    public function deferFilters(bool|Closure $condition = true): static
    {
        $this->hasDeferredFilters = $condition;

        return $this;
    }

    public function hasDeferredFilters(): bool
    {
        return (bool) $this->evaluate($this->hasDeferredFilters);
    }

    public function deselectAllRecordsWhenFiltered(bool|Closure $condition = true): static
    {
        $this->shouldDeselectAllRecordsWhenFiltered = $condition;

        return $this;
    }

    /**
     * A filter setting read only by the selection: the host tails that clear it,
     * the selected-records query's filter re-apply, and
     * `canTrackDeselectedRecords()`.
     */
    public function shouldDeselectAllRecordsWhenFiltered(): bool
    {
        return (bool) $this->evaluate($this->shouldDeselectAllRecordsWhenFiltered);
    }

    public function filtersLayout(FiltersLayout|Closure|null $layout): static
    {
        $this->filtersLayout = $layout;

        return $this;
    }

    public function getFiltersLayout(): FiltersLayout
    {
        return $this->evaluate($this->filtersLayout) ?? FiltersLayout::Dropdown;
    }

    /**
     * Whether the toolbar renders the filters trigger and its dropdown panel.
     */
    public function hasFiltersDropdown(): bool
    {
        $layout = $this->getFiltersLayout();

        if (!in_array($layout, [FiltersLayout::Dropdown, FiltersLayout::Hidden], true)) {
            throw new LogicException(sprintf('Table [%s] requests the [%s] filters layout, which needs chrome the toolkit does not ship yet; use FiltersLayout::Dropdown or FiltersLayout::Hidden.', $this->getLiveComponent()::class, $layout->name));
        }

        return $layout === FiltersLayout::Dropdown;
    }

    /**
     * @param array<string, int|null>|int|Closure|null $columns
     */
    public function filtersFormColumns(array|int|Closure|null $columns): static
    {
        $this->filtersFormColumns = $columns;

        return $this;
    }

    /**
     * @return array<string, int|null>|int
     */
    public function getFiltersFormColumns(): array|int
    {
        return $this->evaluate($this->filtersFormColumns) ?? 1;
    }

    public function filtersFormMaxHeight(string|Closure|null $height): static
    {
        $this->filtersFormMaxHeight = $height;

        return $this;
    }

    public function getFiltersFormMaxHeight(): ?string
    {
        return $this->evaluate($this->filtersFormMaxHeight);
    }

    public function filtersFormWidth(string|Closure|null $width): static
    {
        $this->filtersFormWidth = $width;

        return $this;
    }

    public function getFiltersFormWidth(): ?string
    {
        return $this->evaluate($this->filtersFormWidth);
    }

    public function filtersResetActionPosition(FiltersResetActionPosition|Closure|null $position): static
    {
        $this->filtersResetActionPosition = $position;

        return $this;
    }

    public function getFiltersResetActionPosition(): FiltersResetActionPosition
    {
        return $this->evaluate($this->filtersResetActionPosition) ?? FiltersResetActionPosition::Header;
    }

    public function isFilterable(): bool
    {
        return $this->getFilters() !== [];
    }

    public function getActiveFiltersCount(): int
    {
        return array_reduce(
            $this->getFilters(),
            static fn (int $carry, BaseFilter $filter): int => $carry + $filter->getActiveCount(),
            0,
        );
    }

    public function isFiltered(): bool
    {
        return $this->getActiveFiltersCount() > 0;
    }
}
