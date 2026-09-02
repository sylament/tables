<?php

declare(strict_types=1);

namespace Sylament\Tables\Table\Concerns;

use Closure;
use Sylament\Actions\Action;

trait HasRecordAction
{
    protected string|Closure|null $recordAction = null;

    public function recordAction(string|Closure|null $action): static
    {
        $this->recordAction = $action;

        return $this;
    }

    /**
     * Always a name, never a callable: a class-string naming an action collapses
     * onto that action's default name, and anything else is handed back for the
     * template to resolve — a registered table action, or a live action on the
     * host called with the record key.
     *
     * @param object|array<string, mixed> $record
     */
    public function getRecordAction(object|array $record): ?string
    {
        $action = $this->evaluate(
            $this->recordAction,
            ['record' => $record],
            is_object($record) ? [$record::class => $record] : [],
        );

        if (!$action) {
            return null;
        }

        if (!class_exists($action)) {
            return $action;
        }

        if (!is_subclass_of($action, Action::class)) {
            return $action;
        }

        return $action::getDefaultName() ?? $action;
    }
}
