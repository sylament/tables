<?php

declare(strict_types=1);

namespace Sylament\Tables\Testing;

/**
 * @phpstan-require-extends Testable
 */
trait TestsBulkActions
{
    /**
     * A record object is selected by the key the table host mints for it; anything else
     * is sent as the key it is.
     *
     * @param array<mixed> $records
     */
    public function selectTableRecords(array $records): static
    {
        $host = $this->getTestedTableHost();

        $this->set('selectedTableRecords', array_map(
            static fn (mixed $record): mixed => is_object($record) ? $host->getTableRecordKey($record) : $record,
            $records,
        ));

        return $this;
    }
}
