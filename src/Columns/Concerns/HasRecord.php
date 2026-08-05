<?php

declare(strict_types=1);

namespace Sylament\Tables\Columns\Concerns;

trait HasRecord
{
    /**
     * @var object|array<string, mixed>|null
     */
    protected object|array|null $record = null;

    /**
     * @param object|array<string, mixed> $record
     */
    public function record(object|array $record): static
    {
        $this->record = $record;

        return $this;
    }

    /**
     * @return object|array<string, mixed>|null
     */
    public function getRecord(): object|array|null
    {
        return $this->record;
    }
}
