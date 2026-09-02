<?php

declare(strict_types=1);

namespace Sylament\Tables\Table\Concerns;

use Closure;

trait CanDeferLoading
{
    protected bool|Closure $isLoadingDeferred = false;

    public function deferLoading(bool|Closure $condition = true): static
    {
        $this->isLoadingDeferred = $condition;

        return $this;
    }

    public function isLoadingDeferred(): bool
    {
        return (bool) $this->evaluate($this->isLoadingDeferred);
    }

    /**
     * Whether the records may be read at all. Every records-touching read in the
     * partial is gated on it, so a deferred first render reaches the database
     * for nothing.
     */
    public function isLoaded(): bool
    {
        return $this->getLiveComponent()->isTableLoaded();
    }
}
