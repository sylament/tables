<?php

declare(strict_types=1);

namespace Sylament\Tables\Table\Concerns;

use BackedEnum;
use Closure;
use Sylament\Actions\Action;
use Sylament\Support\Enums\Size;
use Sylament\Support\Icons\IconManager;
use Sylament\Tables\Columns\Column;
use Sylament\Tables\Enums\ColumnManagerLayout;
use Sylament\Tables\Enums\ColumnManagerResetActionPosition;
use Sylament\Tables\Twig\TablesIconAlias;
use Symfony\Contracts\Service\Attribute\Required;
use Twig\Markup;

trait HasColumnManager
{
    protected bool|Closure|null $hasColumnManager = null;

    protected bool|Closure $hasDeferredColumnManager = true;

    protected bool|Closure $hasReorderableColumns = false;

    /**
     * The only persistence flag in the package that defaults on, as Filament's
     * does.
     */
    protected bool|Closure $persistsColumnsInSession = true;

    protected ColumnManagerLayout|Closure|null $columnManagerLayout = null;

    protected ColumnManagerResetActionPosition|Closure|null $columnManagerResetActionPosition = null;

    /**
     * @var array<string, int|null>|int|Closure|null
     */
    protected array|int|Closure|null $columnManagerColumns = null;

    protected string|Closure|null $columnManagerMaxHeight = null;

    protected string|Closure|null $columnManagerWidth = null;

    protected Action|Closure|null $modifyColumnManagerTriggerActionUsing = null;

    protected Action|Closure|null $modifyColumnManagerApplyActionUsing = null;

    private ?IconManager $columnManagerIconManager = null;

    #[Required]
    public function setColumnManagerIconManager(IconManager $iconManager): void
    {
        $this->columnManagerIconManager = $iconManager;
    }

    public function columnManager(bool|Closure|null $condition = true): static
    {
        $this->hasColumnManager = $condition;

        return $this;
    }

    public function deferColumnManager(bool|Closure $condition = true): static
    {
        $this->hasDeferredColumnManager = $condition;

        return $this;
    }

    public function reorderableColumns(bool|Closure $condition = true): static
    {
        $this->hasReorderableColumns = $condition;

        return $this;
    }

    public function persistColumnsInSession(bool|Closure $condition = true): static
    {
        $this->persistsColumnsInSession = $condition;

        return $this;
    }

    /**
     * Whether the toolbar offers the manager at all. An explicit setting wins;
     * otherwise the manager appears for whatever it would have to edit.
     */
    public function hasColumnManager(): bool
    {
        $hasColumnManager = $this->evaluate($this->hasColumnManager);

        if ($hasColumnManager !== null) {
            return (bool) $hasColumnManager;
        }

        return $this->hasReorderableColumns() || $this->hasToggleableColumns();
    }

    public function hasReorderableColumns(): bool
    {
        return (bool) $this->evaluate($this->hasReorderableColumns);
    }

    public function hasDeferredColumnManager(): bool
    {
        return (bool) $this->evaluate($this->hasDeferredColumnManager);
    }

    public function persistsColumnsInSession(): bool
    {
        return (bool) $this->evaluate($this->persistsColumnsInSession);
    }

    public function hasToggleableColumns(): bool
    {
        return array_any($this->getColumns(), static fn (Column $column): bool => $column->isToggleable());
    }

    public function columnManagerLayout(ColumnManagerLayout|Closure|null $layout): static
    {
        $this->columnManagerLayout = $layout;

        return $this;
    }

    public function getColumnManagerLayout(): ColumnManagerLayout
    {
        return $this->evaluate($this->columnManagerLayout) ?? ColumnManagerLayout::Dropdown;
    }

    /**
     * Whether the toolbar renders the trigger and its dropdown panel.
     */
    public function hasColumnManagerDropdown(): bool
    {
        return $this->hasColumnManager() && $this->getColumnManagerLayout() === ColumnManagerLayout::Dropdown;
    }

    /**
     * Whether the manager renders inside a modal the trigger opens.
     */
    public function hasColumnManagerModal(): bool
    {
        return $this->hasColumnManager() && $this->getColumnManagerLayout() === ColumnManagerLayout::Modal;
    }

    public function columnManagerResetActionPosition(ColumnManagerResetActionPosition|Closure|null $position): static
    {
        $this->columnManagerResetActionPosition = $position;

        return $this;
    }

    public function getColumnManagerResetActionPosition(): ColumnManagerResetActionPosition
    {
        return $this->evaluate($this->columnManagerResetActionPosition) ?? ColumnManagerResetActionPosition::Header;
    }

    /**
     * @param array<string, int|null>|int|Closure $columns
     */
    public function columnManagerColumns(array|int|Closure $columns): static
    {
        $this->columnManagerColumns = $columns;

        return $this;
    }

    /**
     * @return array<string, int|null>|int
     */
    public function getColumnManagerColumns(): array|int
    {
        return $this->evaluate($this->columnManagerColumns) ?? 1;
    }

    public function columnManagerMaxHeight(string|Closure|null $height): static
    {
        $this->columnManagerMaxHeight = $height;

        return $this;
    }

    public function getColumnManagerMaxHeight(): ?string
    {
        return $this->evaluate($this->columnManagerMaxHeight);
    }

    public function columnManagerWidth(string|Closure|null $width): static
    {
        $this->columnManagerWidth = $width;

        return $this;
    }

    public function getColumnManagerWidth(): ?string
    {
        return $this->evaluate($this->columnManagerWidth);
    }

    public function columnManagerTriggerAction(?Closure $callback): static
    {
        $this->modifyColumnManagerTriggerActionUsing = $callback;

        return $this;
    }

    public function columnManagerApplyAction(?Closure $callback): static
    {
        $this->modifyColumnManagerApplyActionUsing = $callback;

        return $this;
    }

    /**
     * The dialog element id the modal layout renders under and its trigger's
     * invoker command points at.
     */
    public function getColumnManagerModalId(): string
    {
        return $this->getLiveComponent()->getTableHostId() . '.table.column-manager';
    }

    /**
     * Opens the manager chrome from the client alone — the dropdown panel is
     * the surrounding component's, the modal is reached by the invoker command
     * stamped on for that layout — so the trigger wires no click of its own and
     * never reaches the server.
     *
     * The command is stamped after the customization, as the filters trigger
     * stamps its own: `extraAttributes()` replaces unless told to merge, so a
     * closure setting attributes of its own would otherwise leave the modal
     * layout with nothing that opens it.
     */
    public function getColumnManagerTriggerAction(): Action
    {
        $action = Action::make('openColumnManager')
            ->label('Column manager')
            ->iconButton()
            ->icon($this->resolveColumnManagerIcon(TablesIconAlias::ACTIONS_COLUMN_MANAGER, 'heroicons:view-columns-20-solid'))
            ->color('gray')
            ->liveComponentClickHandlerEnabled(false)
            ->table($this);

        $action = $this->evaluate($this->modifyColumnManagerTriggerActionUsing, ['action' => $action]) ?? $action;

        if ($this->hasColumnManagerModal()) {
            $action->extraAttributes(['command' => 'show-modal', 'commandfor' => $this->getColumnManagerModalId()], merge: true);
        }

        return $action;
    }

    /**
     * Visibility is the defer flag: a manager applying live has nothing to
     * apply, and the chrome keys its footer off this action.
     */
    public function getColumnManagerApplyAction(): Action
    {
        $action = Action::make('applyTableColumnManager')
            ->label('Apply columns')
            ->action('applyTableColumnManager')
            ->visible($this->hasDeferredColumnManager())
            ->button()
            ->defaultSize(Size::Small)
            ->table($this);

        return $this->evaluate($this->modifyColumnManagerApplyActionUsing, ['action' => $action]) ?? $action;
    }

    /**
     * The manager arrives through a setter of this trait's own name: `Table`
     * flattens `CanReorderRecords`' `setIconManager()` into the same class, and
     * a second setter under that name is a trait collision rather than a second
     * injection. A table built outside the container — a unit fixture — falls
     * back to a bare manager, which resolves no alias and answers the fallback
     * glyph rather than failing to build the action.
     */
    private function resolveColumnManagerIcon(string $alias, string $fallback): string|BackedEnum|Markup
    {
        return ($this->columnManagerIconManager ??= new IconManager())->resolve($alias) ?? $fallback;
    }
}
