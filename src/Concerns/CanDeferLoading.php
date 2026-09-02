<?php

declare(strict_types=1);

namespace Sylament\Tables\Concerns;

use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveProp;

trait CanDeferLoading
{
    /**
     * Whether the deferred window has closed. A transient render mode rather
     * than browsing state, so it carries no URL mapping and no session half; it
     * mutates only through {@see loadTable()}.
     */
    #[LiveProp]
    public bool $isTableLoaded = false;

    #[LiveAction]
    public function loadTable(): void
    {
        $this->isTableLoaded = true;
    }

    /**
     * Re-gated by the definition on every read, so a table that has since
     * stopped deferring loads at once whatever the flag says.
     */
    public function isTableLoaded(): bool
    {
        if (!$this->getTable()->isLoadingDeferred()) {
            return true;
        }

        return $this->isTableLoaded;
    }
}
