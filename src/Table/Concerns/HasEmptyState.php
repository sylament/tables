<?php

declare(strict_types=1);

namespace Sylament\Tables\Table\Concerns;

use BackedEnum;
use Closure;
use InvalidArgumentException;
use Sylament\Actions\Action;
use Sylament\Actions\ActionGroup;
use Twig\Markup;

use function Sylament\Support\wrap;

trait HasEmptyState
{
    protected Markup|Closure|null $emptyState = null;

    /**
     * @var list<Action|ActionGroup>
     */
    protected array $emptyStateActions = [];

    protected string|Markup|Closure|null $emptyStateHeading = null;

    protected string|Markup|Closure|null $emptyStateDescription = null;

    protected string|BackedEnum|Markup|Closure|null $emptyStateIcon = null;

    /**
     * The whole-block escape hatch: set, it replaces the icon, heading,
     * description and actions rather than joining them.
     */
    public function emptyState(Markup|Closure|null $emptyState): static
    {
        $this->emptyState = $emptyState;

        return $this;
    }

    /**
     * Clear-then-push, matching the header setter's shape without its eviction:
     * the previous set leaves the flat action cache alone, so an action named
     * there stays resolvable — and `hasAction('create')`, the default
     * description's gate, keeps counting it.
     *
     * @param array<Action|ActionGroup>|ActionGroup $actions
     */
    public function emptyStateActions(array|ActionGroup $actions, bool $shouldOverwriteExistingActions = false): static
    {
        $this->emptyStateActions = [];

        return $this->pushEmptyStateActions($actions, $shouldOverwriteExistingActions);
    }

    /**
     * @param array<Action|ActionGroup>|ActionGroup $actions
     *
     * @throws InvalidArgumentException when a member is neither an action nor a group
     */
    public function pushEmptyStateActions(array|ActionGroup $actions, bool $shouldOverwriteExistingActions = false): static
    {
        foreach (wrap($actions) as $action) {
            if ($action instanceof ActionGroup) {
                $action->table($this);

                foreach ($action->getFlatActions() as $flatAction) {
                    $this->cacheAction($flatAction, $shouldOverwriteExistingActions);
                }
            } elseif ($action instanceof Action) {
                $action->table($this);

                $this->cacheAction($action, $shouldOverwriteExistingActions);
            } else {
                throw new InvalidArgumentException(sprintf('Table empty state actions must be an instance of [%s] or [%s], got [%s].', Action::class, ActionGroup::class, get_debug_type($action)));
            }

            $this->emptyStateActions[] = $action;
        }

        return $this;
    }

    public function emptyStateHeading(string|Markup|Closure|null $heading): static
    {
        $this->emptyStateHeading = $heading;

        return $this;
    }

    public function emptyStateDescription(string|Markup|Closure|null $description): static
    {
        $this->emptyStateDescription = $description;

        return $this;
    }

    public function emptyStateIcon(string|BackedEnum|Markup|Closure|null $icon): static
    {
        $this->emptyStateIcon = $icon;

        return $this;
    }

    public function getEmptyState(): ?Markup
    {
        return $this->evaluate($this->emptyState);
    }

    /**
     * The registered set, unfiltered — the template drops the hidden ones at
     * render, the way Filament's blade does.
     *
     * @return list<Action|ActionGroup>
     */
    public function getEmptyStateActions(): array
    {
        return $this->emptyStateActions;
    }

    public function getEmptyStateHeading(): string|Markup
    {
        return $this->evaluate($this->emptyStateHeading) ?? sprintf('No %s', $this->getPluralRecordLabel());
    }

    public function getEmptyStateDescription(): string|Markup|null
    {
        return $this->evaluate($this->emptyStateDescription);
    }

    public function getEmptyStateIcon(): string|BackedEnum|Markup|null
    {
        return $this->evaluate($this->emptyStateIcon);
    }
}
