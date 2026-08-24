<?php

declare(strict_types=1);

namespace Sylament\Tables\Table\Concerns;

use Sylament\Actions\Action;
use Sylament\Actions\BulkAction;

trait HasActions
{
    /**
     * @var array<string, Action>
     */
    protected array $flatActions = [];

    /**
     * @var array<string, BulkAction>
     */
    protected array $flatBulkActions = [];

    public function getAction(string $name): ?Action
    {
        return $this->getFlatActions()[$name] ?? null;
    }

    public function getBulkAction(string $name): ?BulkAction
    {
        return $this->getFlatBulkActions()[$name] ?? null;
    }

    /**
     * @return array<string, Action>
     */
    public function getFlatActions(): array
    {
        return $this->flatActions;
    }

    /**
     * @return array<string, BulkAction>
     */
    public function getFlatBulkActions(): array
    {
        return $this->flatBulkActions;
    }

    public function hasAction(string $name): bool
    {
        return array_key_exists($name, $this->getFlatActions());
    }

    public function hasBulkAction(string $name): bool
    {
        return array_key_exists($name, $this->getFlatBulkActions());
    }

    /**
     * The two maps split on the class, not on `isBulk()`: an
     * `Action::make(…)->bulk()` that is no `BulkAction` lands in the record map,
     * where a bulk mount reaches it only through the resolver's fall-through.
     */
    protected function cacheAction(Action $action, bool $shouldOverwriteExistingAction = true): void
    {
        if (!$shouldOverwriteExistingAction) {
            if ($action instanceof BulkAction) {
                $this->flatBulkActions[$action->getName()] ??= $action;

                return;
            }

            $this->flatActions[$action->getName()] ??= $action;

            return;
        }

        if ($action instanceof BulkAction) {
            $this->flatBulkActions[$action->getName()] = $action;

            return;
        }

        $this->flatActions[$action->getName()] = $action;
    }

    /**
     * @param array<string, Action> $actions
     */
    protected function mergeCachedFlatActions(array $actions): void
    {
        foreach ($actions as $action) {
            $this->cacheAction($action);
        }
    }

    /**
     * Evicts by identity, never by name, so a set re-registering itself cannot
     * drop a same-named action another registration owns.
     *
     * @param array<Action> $actions
     */
    protected function removeCachedActions(array $actions): void
    {
        $this->flatActions = array_filter(
            $this->flatActions,
            static fn (Action $existingAction): bool => !in_array($existingAction, $actions, true),
        );

        $this->flatBulkActions = array_filter(
            $this->flatBulkActions,
            static fn (Action $existingAction): bool => !in_array($existingAction, $actions, true),
        );
    }
}
