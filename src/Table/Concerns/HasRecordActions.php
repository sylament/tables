<?php

declare(strict_types=1);

namespace Sylament\Tables\Table\Concerns;

use Closure;
use InvalidArgumentException;
use Sylament\Actions\Action;
use Sylament\Actions\ActionGroup;
use Sylament\Support\Enums\Size;
use Sylament\Tables\Enums\RecordActionsPosition;

use function Sylament\Support\wrap;

trait HasRecordActions
{
    /**
     * @var list<Action|ActionGroup>
     */
    protected array $recordActions = [];

    protected RecordActionsPosition|Closure|null $recordActionsPosition = null;

    /**
     * @param array<Action|ActionGroup>|ActionGroup $actions
     */
    public function recordActions(array|ActionGroup $actions, RecordActionsPosition|Closure|null $position = null): static
    {
        $this->recordActions = [];
        $this->pushRecordActions($actions);

        if ($position !== null) {
            $this->recordActionsPosition($position);
        }

        return $this;
    }

    /**
     * @param array<Action|ActionGroup>|ActionGroup $actions
     *
     * @throws InvalidArgumentException when a member is neither an action nor a group
     */
    public function pushRecordActions(array|ActionGroup $actions): static
    {
        foreach (wrap($actions) as $action) {
            if ($action instanceof ActionGroup) {
                $action->table($this);

                $this->mergeCachedFlatActions($action->getFlatActions());
            } elseif ($action instanceof Action) {
                $action->table($this);
                $action->defaultSize(Size::Small);
                $action->defaultTemplate(Action::LINK_TEMPLATE);

                $this->cacheAction($action);
            } else {
                throw new InvalidArgumentException(sprintf('Table record actions must be an instance of [%s] or [%s], got [%s].', Action::class, ActionGroup::class, get_debug_type($action)));
            }

            $this->recordActions[] = $action;
        }

        return $this;
    }

    /**
     * @return list<Action|ActionGroup>
     */
    public function getRecordActions(): array
    {
        $defaultGroupDropdownPlacement = in_array($this->getRecordActionsPosition(), [RecordActionsPosition::BeforeCells, RecordActionsPosition::BeforeColumns], true)
            ? 'bottom-start'
            : 'bottom-end';

        return array_map(
            static fn (Action|ActionGroup $action): Action|ActionGroup => $action instanceof ActionGroup
                ? $action->defaultDropdownPlacement($defaultGroupDropdownPlacement)
                : $action,
            $this->recordActions,
        );
    }

    /**
     * @return array<string, Action>
     */
    public function getFlatRecordActions(): array
    {
        $flatActions = [];

        foreach ($this->getRecordActions() as $action) {
            if ($action instanceof ActionGroup) {
                $flatActions = [...$flatActions, ...$action->getFlatActions()];

                continue;
            }

            $flatActions[$action->getName()] = $action;
        }

        return $flatActions;
    }

    /**
     * Per-record clones of the registered actions, record-bound and with the
     * hidden ones dropped — a record whose actions are all hidden keeps an
     * empty entry, so the template can tell "no actions here" from "no such
     * record".
     *
     * @return array<array-key, list<Action|ActionGroup>>
     */
    public function getVisibleRecordActionsByRecordKey(): array
    {
        if ($this->recordActions === []) {
            return [];
        }

        $visibleByKey = [];

        foreach ($this->getRecords() as $recordKey => $record) {
            $visibleByKey[$recordKey] = [];

            foreach ($this->getRecordActions() as $action) {
                $clone = $action->getClone();
                $clone->record($record);

                if ($clone->isHidden()) {
                    continue;
                }

                $visibleByKey[$recordKey][] = $clone;
            }
        }

        return $visibleByKey;
    }

    public function recordActionsPosition(RecordActionsPosition|Closure|null $position): static
    {
        $this->recordActionsPosition = $position;

        return $this;
    }

    public function getRecordActionsPosition(): RecordActionsPosition
    {
        return $this->evaluate($this->recordActionsPosition) ?? RecordActionsPosition::AfterColumns;
    }
}
