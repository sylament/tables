<?php

declare(strict_types=1);

namespace Sylament\Tables\Filters\Concerns;

use Closure;
use Sylament\Tables\Contracts\InteractsWithTable;

use function Sylament\Support\wrap;

trait CanBeHidden
{
    protected bool|Closure $isHidden = false;

    protected bool|Closure $isVisible = true;

    public function hidden(bool|Closure $condition = true): static
    {
        $this->isHidden = $condition;

        return $this;
    }

    /**
     * @param string|array<string> $hosts
     */
    public function hiddenOn(string|array $hosts): static
    {
        return $this->hidden(static fn (InteractsWithTable $liveComponent): bool => array_any(wrap($hosts), fn (string $host): bool => $liveComponent instanceof $host));
    }

    public function visible(bool|Closure $condition = true): static
    {
        $this->isVisible = $condition;

        return $this;
    }

    /**
     * @param string|array<string> $hosts
     */
    public function visibleOn(string|array $hosts): static
    {
        return $this->visible(static fn (InteractsWithTable $liveComponent): bool => array_any(wrap($hosts), fn (string $host): bool => $liveComponent instanceof $host));
    }

    public function isHidden(): bool
    {
        if ($this->evaluate($this->isHidden)) {
            return true;
        }

        return !$this->evaluate($this->isVisible);
    }

    public function isVisible(): bool
    {
        return !$this->isHidden();
    }
}
