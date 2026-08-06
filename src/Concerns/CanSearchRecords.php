<?php

declare(strict_types=1);

namespace Sylament\Tables\Concerns;

use Symfony\UX\LiveComponent\Attribute\LiveProp;

trait CanSearchRecords
{
    #[LiveProp(writable: true, onUpdated: 'updatedTableSearch')]
    public ?string $tableSearch = '';

    public function updatedTableSearch(mixed $previousValue = null): void
    {
        $this->tablePage = 1;
        $this->flushCachedTableRecords();
    }

    /**
     * Trimmed, blank-as-null, and raw user input beyond that — a `records()`
     * closure treats it exactly as it would a query parameter.
     */
    public function getTableSearch(): ?string
    {
        $search = mb_trim((string) $this->tableSearch);

        return $search === '' ? null : $search;
    }
}
