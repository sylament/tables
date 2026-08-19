<?php

declare(strict_types=1);

namespace Sylament\Tables\Filters\Concerns;

use Closure;

trait HasOptions
{
    /**
     * @var array<string|int, string|array<string|int, string>>|Closure|null
     */
    protected array|Closure|null $options = null;

    /**
     * @param array<string|int, string|array<string|int, string>>|Closure|null $options
     */
    public function options(array|Closure|null $options): static
    {
        $this->options = $options;

        return $this;
    }

    /**
     * @return array<string|int, string|array<string|int, string>>
     */
    public function getOptions(): array
    {
        return $this->evaluate($this->options) ?? [];
    }
}
