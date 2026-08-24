<?php

declare(strict_types=1);

namespace Sylament\Tables\Table\Concerns;

use Closure;
use InvalidArgumentException;
use Sylament\Actions\Action;
use Sylament\Actions\ActionGroup;
use Sylament\Tables\Enums\HeaderActionsPosition;

use function Sylament\Support\wrap;

trait HasHeaderActions
{
    /**
     * @var list<Action|ActionGroup>
     */
    protected array $headerActions = [];

    protected HeaderActionsPosition|Closure|null $headerActionsPosition = null;

    /**
     * Destructive-then-push, for the same reason the toolbar setter is: header
     * actions share the flat maps, and the bulk one decides whether the
     * checkboxes render at all.
     *
     * @param array<Action|ActionGroup>|ActionGroup $actions
     */
    public function headerActions(array|ActionGroup $actions, HeaderActionsPosition|Closure|null $position = null): static
    {
        foreach ($this->headerActions as $existingAction) {
            $this->removeCachedActions($existingAction instanceof ActionGroup
                ? $existingAction->getFlatActions()
                : [$existingAction]);
        }

        $this->headerActions = [];

        $this->pushHeaderActions($actions);

        if ($position !== null) {
            $this->headerActionsPosition($position);
        }

        return $this;
    }

    /**
     * @param array<Action|ActionGroup>|ActionGroup $actions
     *
     * @throws InvalidArgumentException when a member is neither an action nor a group
     */
    public function pushHeaderActions(array|ActionGroup $actions): static
    {
        foreach (wrap($actions) as $action) {
            if ($action instanceof ActionGroup) {
                $action->table($this);

                $this->mergeCachedFlatActions($action->getFlatActions());
            } elseif ($action instanceof Action) {
                $action->table($this);

                $this->cacheAction($action);
            } else {
                throw new InvalidArgumentException(sprintf('Table header actions must be an instance of [%s] or [%s], got [%s].', Action::class, ActionGroup::class, get_debug_type($action)));
            }

            $this->headerActions[] = $action;
        }

        return $this;
    }

    public function headerActionsPosition(HeaderActionsPosition|Closure|null $position = null): static
    {
        $this->headerActionsPosition = $position;

        return $this;
    }

    /**
     * @return list<Action|ActionGroup>
     */
    public function getHeaderActions(): array
    {
        return $this->headerActions;
    }

    public function getHeaderActionsPosition(): HeaderActionsPosition
    {
        return $this->evaluate($this->headerActionsPosition) ?? HeaderActionsPosition::Adaptive;
    }
}
