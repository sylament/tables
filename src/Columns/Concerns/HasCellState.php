<?php

declare(strict_types=1);

namespace Sylament\Tables\Columns\Concerns;

use Symfony\Component\PropertyAccess\Exception\AccessException;

trait HasCellState
{
    protected mixed $getStateUsing = null;

    /**
     * @var array<string, mixed>
     */
    protected array $cachedState = [];

    public function getStateUsing(mixed $callback): static
    {
        $this->getStateUsing = $callback;

        return $this;
    }

    public function state(mixed $state): static
    {
        return $this->getStateUsing($state);
    }

    public function getState(): mixed
    {
        $recordKey = $this->getRecordKey();

        if ($recordKey === null) {
            return $this->resolveState();
        }

        if (array_key_exists($recordKey, $this->cachedState)) {
            return $this->cachedState[$recordKey];
        }

        return $this->cachedState[$recordKey] = $this->resolveState();
    }

    protected function resolveState(): mixed
    {
        return ($this->getStateUsing !== null)
            ? $this->evaluate($this->getStateUsing)
            : $this->getStateFromRecord();
    }

    public function getStateFromRecord(): mixed
    {
        $record = $this->getRecord();

        if ($record === null) {
            return null;
        }

        if (is_array($record)) {
            return $this->getPropertyAccessor()->getFromArray($record, $this->getName());
        }

        try {
            return $this->getPropertyAccessor()->getFromRecord($record, $this->getName());
        } catch (AccessException) {
            return null;
        }
    }

    public function clearCachedState(): void
    {
        $this->cachedState = [];
    }
}
