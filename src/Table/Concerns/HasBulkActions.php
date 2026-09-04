<?php

declare(strict_types=1);

namespace Sylament\Tables\Table\Concerns;

use Closure;
use Sylament\Actions\Action;
use Sylament\Actions\ActionGroup;
use Sylament\Actions\BulkActionGroup;
use Sylament\Tables\Enums\RecordCheckboxPosition;

trait HasBulkActions
{
    protected ?Closure $checkIfRecordIsSelectableUsing = null;

    protected bool|Closure|null $selectsCurrentPageOnly = false;

    protected RecordCheckboxPosition|Closure|null $recordCheckboxPosition = null;

    protected bool|Closure|null $isSelectable = null;

    protected bool|Closure $canTrackDeselectedRecords = true;

    protected bool|Closure $selectsGroupsOnly = false;

    /**
     * @deprecated Use toolbarActions() instead.
     *
     * @param array<Action|ActionGroup>|ActionGroup $actions
     */
    public function bulkActions(array|ActionGroup $actions): static
    {
        return $this->toolbarActions($actions);
    }

    /**
     * @deprecated Use pushToolbarActions() instead.
     *
     * @param array<Action|ActionGroup>|ActionGroup $actions
     */
    public function pushBulkActions(array|ActionGroup $actions): static
    {
        return $this->pushToolbarActions($actions);
    }

    /**
     * @param array<Action|ActionGroup> $actions
     */
    public function groupedBulkActions(array $actions): static
    {
        return $this->toolbarActions([BulkActionGroup::make($actions)]);
    }

    public function checkIfRecordIsSelectableUsing(?Closure $callback): static
    {
        $this->checkIfRecordIsSelectableUsing = $callback;

        return $this;
    }

    /**
     * Whether a selectability callback was set — a presence test, never an
     * evaluation, because every reader uses it to decide whether the expensive
     * per-record walk is worth running at all.
     */
    public function checksIfRecordIsSelectable(): bool
    {
        return $this->checkIfRecordIsSelectableUsing instanceof Closure;
    }

    /**
     * @param object|array<string, mixed> $record
     */
    public function isRecordSelectable(object|array $record): bool
    {
        $callback = $this->checkIfRecordIsSelectableUsing;

        if (!$callback instanceof Closure) {
            return true;
        }

        return (bool) $this->evaluateRecordSelectability($callback, $record);
    }

    public function selectCurrentPageOnly(bool|Closure $condition = true): static
    {
        $this->selectsCurrentPageOnly = $condition;

        return $this;
    }

    public function selectsCurrentPageOnly(): bool
    {
        return (bool) $this->evaluate($this->selectsCurrentPageOnly) || !$this->hasQuery();
    }

    /**
     * Removes every affordance that selects a whole page — the header checkbox
     * and the banner's select-all-across-pages link — leaving the group and
     * record checkboxes as the only ways to select. Selection itself, the
     * checkbox column and its header cell are untouched.
     */
    public function selectGroupsOnly(bool|Closure $condition = true): static
    {
        $this->selectsGroupsOnly = $condition;

        return $this;
    }

    public function selectsGroupsOnly(): bool
    {
        return (bool) $this->evaluate($this->selectsGroupsOnly);
    }

    public function selectable(bool|Closure|null $condition = true): static
    {
        $this->isSelectable = $condition;

        return $this;
    }

    /**
     * Selection turns itself on for the first visible bulk action, so the flat
     * bulk map is the checkbox-visibility signal; an explicit `selectable()`
     * bool overrides it in both directions.
     */
    public function isSelectionEnabled(): bool
    {
        $isSelectable = $this->evaluate($this->isSelectable);

        if (is_bool($isSelectable)) {
            return $isSelectable;
        }

        foreach ($this->getFlatBulkActions() as $action) {
            if ($action->isVisible()) {
                return true;
            }
        }

        return false;
    }

    public function trackDeselectedRecords(bool|Closure $condition = true): static
    {
        $this->canTrackDeselectedRecords = $condition;

        return $this;
    }

    /**
     * An inverted selection has no meaning once the filtered set can drift
     * under it, so a table that keeps its selection across a filter change
     * cannot track deselections at all.
     */
    public function canTrackDeselectedRecords(): bool
    {
        if (!$this->shouldDeselectAllRecordsWhenFiltered()) {
            return false;
        }

        return (bool) $this->evaluate($this->canTrackDeselectedRecords);
    }

    public function recordCheckboxPosition(RecordCheckboxPosition|Closure|null $position = null): static
    {
        $this->recordCheckboxPosition = $position;

        return $this;
    }

    public function getRecordCheckboxPosition(): RecordCheckboxPosition
    {
        return $this->evaluate($this->recordCheckboxPosition) ?? RecordCheckboxPosition::BeforeCells;
    }

    public function getAllSelectableRecordsCount(): ?int
    {
        return $this->getLiveComponent()->getAllSelectableTableRecordsCount();
    }

    /**
     * `evaluate()` is generic over the value it is handed, so a bare `Closure`
     * argument infers a `Closure` return; this wrapper's `mixed` leaves the
     * narrowing to the caller.
     *
     * @param object|array<string, mixed> $record
     */
    private function evaluateRecordSelectability(Closure $callback, object|array $record): mixed
    {
        return $this->evaluate(
            $callback,
            namedInjections: [
                'record' => $record,
            ],
            typedInjections: is_object($record) ? [
                $record::class => $record,
            ] : [],
        );
    }
}
