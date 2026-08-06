<?php

declare(strict_types=1);

namespace Sylament\Tables\Table\Concerns;

use Sylament\Actions\Action;

trait HasActions
{
    /**
     * @var array<string, Action>
     */
    protected array $flatActions = [];

    public function getAction(string $name): ?Action
    {
        return $this->getFlatActions()[$name] ?? null;
    }

    /**
     * @return array<string, Action>
     */
    public function getFlatActions(): array
    {
        return $this->flatActions;
    }

    public function hasAction(string $name): bool
    {
        return array_key_exists($name, $this->getFlatActions());
    }

    protected function cacheAction(Action $action, bool $shouldOverwriteExistingAction = true): void
    {
        if (!$shouldOverwriteExistingAction) {
            $this->flatActions[$action->getName()] ??= $action;

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
}
