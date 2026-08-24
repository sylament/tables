<?php

declare(strict_types=1);

namespace Sylament\Tables\Table\Concerns;

use InvalidArgumentException;
use Sylament\Actions\Action;
use Sylament\Actions\ActionGroup;

use function Sylament\Support\wrap;

trait HasToolbarActions
{
    /**
     * @var list<Action|ActionGroup>
     */
    protected array $toolbarActions = [];

    /**
     * Destructive-then-push, and the eviction is not incidental: the flat bulk
     * map is the checkbox-visibility signal, so a setter that only appended
     * would leave a replaced bulk action enabling selection forever.
     *
     * @param array<Action|ActionGroup>|ActionGroup $actions
     */
    public function toolbarActions(array|ActionGroup $actions): static
    {
        foreach ($this->toolbarActions as $existingAction) {
            $this->removeCachedActions($existingAction instanceof ActionGroup
                ? $existingAction->getFlatActions()
                : [$existingAction]);
        }

        $this->toolbarActions = [];

        $this->pushToolbarActions($actions);

        return $this;
    }

    /**
     * @param array<Action|ActionGroup>|ActionGroup $actions
     *
     * @throws InvalidArgumentException when a member is neither an action nor a group
     */
    public function pushToolbarActions(array|ActionGroup $actions): static
    {
        foreach (wrap($actions) as $action) {
            if ($action instanceof ActionGroup) {
                $action->table($this);

                $this->mergeCachedFlatActions($action->getFlatActions());
            } elseif ($action instanceof Action) {
                $action->table($this);

                $this->cacheAction($action);
            } else {
                throw new InvalidArgumentException(sprintf('Table toolbar actions must be an instance of [%s] or [%s], got [%s].', Action::class, ActionGroup::class, get_debug_type($action)));
            }

            $this->toolbarActions[] = $action;
        }

        return $this;
    }

    /**
     * @return list<Action|ActionGroup>
     */
    public function getToolbarActions(): array
    {
        return $this->toolbarActions;
    }
}
