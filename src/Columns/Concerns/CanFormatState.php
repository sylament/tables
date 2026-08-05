<?php

declare(strict_types=1);

namespace Sylament\Tables\Columns\Concerns;

trait CanFormatState
{
    protected mixed $formatStateUsing = null;

    public function formatStateUsing(mixed $callback): static
    {
        $this->formatStateUsing = $callback;

        return $this;
    }

    public function formatState(mixed $state): mixed
    {
        if ($this->formatStateUsing === null) {
            return $state;
        }

        return $this->evaluate($this->formatStateUsing, [
            'state' => $state,
        ]);
    }

    public function getFormattedState(): mixed
    {
        return $this->formatState($this->getState());
    }
}
