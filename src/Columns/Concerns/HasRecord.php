<?php

declare(strict_types=1);

namespace Sylament\Tables\Columns\Concerns;

trait HasRecord
{
    /**
     * @var object|array<string, mixed>|null
     */
    protected object|array|null $record = null;

    protected ?string $recordKey = null;

    /**
     * @param object|array<string, mixed> $record
     */
    public function record(object|array $record): static
    {
        $this->record = $record;

        return $this;
    }

    /**
     * The key the table addresses this cell's record by; `int` is admitted and
     * stored as its string form.
     */
    public function recordKey(string|int|null $key): static
    {
        $this->recordKey = $key === null ? null : (string) $key;

        return $this;
    }

    public function getRecordKey(): ?string
    {
        return $this->recordKey;
    }

    /**
     * @return object|array<string, mixed>|null
     */
    public function getRecord(): object|array|null
    {
        return $this->record;
    }
}
