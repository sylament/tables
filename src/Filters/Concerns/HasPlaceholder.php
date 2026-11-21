<?php

declare(strict_types=1);

namespace Sylament\Tables\Filters\Concerns;

use Closure;
use Symfony\Contracts\Translation\TranslatableInterface;

trait HasPlaceholder
{
    protected string|TranslatableInterface|Closure|null $placeholder = null;

    public function placeholder(string|TranslatableInterface|Closure|null $placeholder): static
    {
        $this->placeholder = $placeholder;

        return $this;
    }

    public function getPlaceholder(): ?string
    {
        $placeholder = $this->translateText($this->evaluate($this->placeholder));

        return $placeholder === null ? null : (string) $placeholder;
    }
}
