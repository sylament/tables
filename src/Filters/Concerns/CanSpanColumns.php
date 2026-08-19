<?php

declare(strict_types=1);

namespace Sylament\Tables\Filters\Concerns;

use Closure;

trait CanSpanColumns
{
    /**
     * @var array<string, int|string|Closure|null>|int|string|Closure|null
     */
    protected array|int|string|Closure|null $columnSpan = 1;

    /**
     * @var array<string, int|string|Closure|null>|int|string|Closure|null
     */
    protected array|int|string|Closure|null $columnStart = null;

    /**
     * @param array<string, int|string|Closure|null>|int|string|Closure|null $span
     */
    public function columnSpan(array|int|string|Closure|null $span): static
    {
        $this->columnSpan = $span;

        return $this;
    }

    public function columnSpanFull(): static
    {
        return $this->columnSpan(['default' => 'full']);
    }

    /**
     * @param array<string, int|string|Closure|null>|int|string|Closure|null $start
     */
    public function columnStart(array|int|string|Closure|null $start): static
    {
        $this->columnStart = $start;

        return $this;
    }

    /**
     * Raw and unevaluated — the `Group` this is forwarded to evaluates it itself.
     *
     * @return array<string, int|string|Closure|null>|int|string|Closure|null
     */
    public function getColumnSpan(): array|int|string|Closure|null
    {
        return $this->columnSpan;
    }

    /**
     * Raw and unevaluated, like {@see getColumnSpan()}.
     *
     * @return array<string, int|string|Closure|null>|int|string|Closure|null
     */
    public function getColumnStart(): array|int|string|Closure|null
    {
        return $this->columnStart;
    }
}
