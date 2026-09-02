<?php

declare(strict_types=1);

namespace Sylament\Tables\Table\Concerns;

use BackedEnum;
use Closure;
use LogicException;
use Sylament\Actions\Action;
use Sylament\Schemas\Components\Group;
use Sylament\Support\Enums\Size;
use Sylament\Support\Icons\IconManager;
use Sylament\Tables\Enums\FiltersLayout;
use Sylament\Tables\Enums\FiltersResetActionPosition;
use Sylament\Tables\Filters\BaseFilter;
use Sylament\Tables\Twig\TablesIconAlias;
use Twig\Markup;

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

    protected bool|Closure $persistsFiltersInSession = false;

    protected Action|Closure|null $modifyFiltersTriggerActionUsing = null;

    protected Action|Closure|null $modifyFiltersApplyActionUsing = null;

    protected Action|Closure|null $modifyFiltersRemoveAllActionUsing = null;

    public function persistFiltersInSession(bool|Closure $condition = true): static
    {
        $this->persistsFiltersInSession = $condition;

        return $this;
    }

    public function persistsFiltersInSession(): bool
    {
        return (bool) $this->evaluate($this->persistsFiltersInSession);
    }

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
     * The layout every chrome branch reads, refused for the four whose
     * before/after-content sidebar the toolkit does not ship.
     * `getFiltersLayout()` stays total, so asking what the table wants never
     * trips the refusal.
     */
    private function getConsumableFiltersLayout(): FiltersLayout
    {
        $layout = $this->getFiltersLayout();

        if (in_array($layout, [FiltersLayout::BeforeContent, FiltersLayout::BeforeContentCollapsible, FiltersLayout::AfterContent, FiltersLayout::AfterContentCollapsible], true)) {
            throw new LogicException(sprintf('Table [%s] requests the [%s] filters layout, which needs chrome the toolkit does not ship yet; use FiltersLayout::Dropdown, Modal, AboveContent, AboveContentCollapsible, BelowContent or Hidden.', $this->getLiveComponent()::class, $layout->name));
        }

        return $layout;
    }

    /**
     * Whether the toolbar renders the filters trigger and its dropdown panel.
     */
    public function hasFiltersDropdown(): bool
    {
        return $this->getConsumableFiltersLayout() === FiltersLayout::Dropdown;
    }

    /**
     * Whether the filters form renders inside a modal the trigger opens.
     */
    public function hasFiltersModal(): bool
    {
        return $this->getConsumableFiltersLayout() === FiltersLayout::Modal;
    }

    /**
     * Whether the toolbar renders the trigger at all — the two dialog layouts.
     * The collapsible above-content panel carries its own toggle instead.
     */
    public function hasFiltersTrigger(): bool
    {
        return in_array($this->getConsumableFiltersLayout(), [FiltersLayout::Dropdown, FiltersLayout::Modal], true);
    }

    public function hasFiltersAboveContent(): bool
    {
        return in_array($this->getConsumableFiltersLayout(), [FiltersLayout::AboveContent, FiltersLayout::AboveContentCollapsible], true);
    }

    public function hasFiltersBelowContent(): bool
    {
        return $this->getConsumableFiltersLayout() === FiltersLayout::BelowContent;
    }

    public function hasCollapsibleFilters(): bool
    {
        return $this->getConsumableFiltersLayout() === FiltersLayout::AboveContentCollapsible;
    }

    /**
     * The dialog element id the modal layout renders under and its trigger's
     * invoker command points at.
     */
    public function getFiltersModalId(): string
    {
        return $this->getLiveComponent()->getTableHostId() . '.table.filters';
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
     * A configured count wins; the layout match is only the fallback, so the
     * responsive spread reaches the three layouts that span the content width.
     *
     * @return array<string, int|null>|int
     */
    public function getFiltersFormColumns(): array|int
    {
        return $this->evaluate($this->filtersFormColumns) ?? match ($this->getFiltersLayout()) {
            FiltersLayout::AboveContent, FiltersLayout::AboveContentCollapsible, FiltersLayout::BelowContent => ['sm' => 2, 'lg' => 3, 'xl' => 4, '2xl' => 5],
            default => 1,
        };
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

    public function filtersTriggerAction(?Closure $callback): static
    {
        $this->modifyFiltersTriggerActionUsing = $callback;

        return $this;
    }

    public function filtersApplyAction(?Closure $callback): static
    {
        $this->modifyFiltersApplyActionUsing = $callback;

        return $this;
    }

    public function filtersRemoveAllAction(?Closure $callback): static
    {
        $this->modifyFiltersRemoveAllActionUsing = $callback;

        return $this;
    }

    /**
     * Opens the filters chrome from the client alone — the dropdown panel is
     * the surrounding component's, the modal is reached by the invoker command
     * stamped on for that layout — so the trigger wires no click of its own and
     * never reaches the server.
     *
     * The command is stamped after the customization, as Filament stamps its
     * own: `extraAttributes()` replaces unless told to merge, so a closure
     * setting attributes of its own would otherwise leave the modal layout with
     * nothing that opens it.
     */
    public function getFiltersTriggerAction(): Action
    {
        $action = Action::make('openFilters')
            ->label('Filter')
            ->iconButton()
            ->icon($this->resolveFiltersIcon(TablesIconAlias::ACTIONS_FILTER, 'heroicons:funnel-20-solid'))
            ->color('gray')
            ->liveComponentClickHandlerEnabled(false)
            ->table($this);

        $action = $this->evaluate($this->modifyFiltersTriggerActionUsing, ['action' => $action]) ?? $action;

        if ($this->hasFiltersModal()) {
            $action->extraAttributes(['command' => 'show-modal', 'commandfor' => $this->getFiltersModalId()], merge: true);
        }

        return $action;
    }

    /**
     * Visibility is the defer flag: a table filtering live has nothing to
     * apply, and the panel keys its footer off this action.
     */
    public function getFiltersApplyAction(): Action
    {
        $action = Action::make('applyFilters')
            ->label('Apply filters')
            ->action('applyTableFilters')
            ->visible($this->hasDeferredFilters())
            ->button()
            ->defaultSize(Size::Small)
            ->table($this);

        return $this->evaluate($this->modifyFiltersApplyActionUsing, ['action' => $action]) ?? $action;
    }

    /**
     * The layout class is stamped after the customization for the reason the
     * trigger's command is, at lower stakes: a closure setting attributes of its
     * own would otherwise drop the chip row's own styling hook.
     */
    public function getFiltersRemoveAllAction(): Action
    {
        $action = Action::make('removeAllFilters')
            ->label('Remove all filters')
            ->action('removeTableFilters')
            ->iconButton()
            ->icon($this->resolveFiltersIcon(TablesIconAlias::FILTERS_REMOVE_ALL_BUTTON, 'heroicons:x-mark-20-solid'))
            ->color('gray')
            ->defaultSize(Size::Small)
            ->table($this);

        $action = $this->evaluate($this->modifyFiltersRemoveAllActionUsing, ['action' => $action]) ?? $action;

        $action->extraAttributes(['class' => 'sy-ta-filter-indicators-remove-all-btn'], merge: true);

        return $action;
    }

    /**
     * The alias is resolved when the action evaluates its icon, so a table
     * built outside the container — a unit fixture — still answers the
     * fallback glyph rather than failing to resolve the manager.
     */
    private function resolveFiltersIcon(string $alias, string $fallback): Closure
    {
        return static fn (?IconManager $icons = null): string|BackedEnum|Markup => $icons?->resolve($alias) ?? $fallback;
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
