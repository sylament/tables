<?php

declare(strict_types=1);

namespace Sylament\Tables\Filters\Concerns;

use Closure;

trait CanResetState
{
    /**
     * @var array<string, mixed>|Closure|null
     */
    protected array|Closure|null $resetState = null;

    /**
     * @param array<string, mixed>|Closure|null $state
     */
    public function resetState(array|Closure|null $state): static
    {
        $this->resetState = $state;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function getResetState(): array
    {
        return $this->evaluate($this->resetState) ?? [];
    }
}
