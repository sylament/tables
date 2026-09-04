<?php

declare(strict_types=1);

namespace Sylament\Tables\Columns\Concerns;

use Closure;
use Twig\Markup;

trait HasTooltip
{
    protected string|Markup|Closure|null $tooltip = null;

    protected string|Markup|Closure|null $headerTooltip = null;

    protected string|Markup|Closure|null $emptyTooltip = null;

    public function tooltip(string|Markup|Closure|null $tooltip): static
    {
        $this->tooltip = $tooltip;

        return $this;
    }

    public function getTooltip(mixed $state = null): string|Markup|null
    {
        return $this->evaluate($this->tooltip, ['state' => $state]);
    }

    public function headerTooltip(string|Markup|Closure|null $tooltip): static
    {
        $this->headerTooltip = $tooltip;

        return $this;
    }

    public function getHeaderTooltip(): string|Markup|null
    {
        return $this->evaluate($this->headerTooltip);
    }

    public function emptyTooltip(string|Markup|Closure|null $tooltip): static
    {
        $this->emptyTooltip = $tooltip;

        return $this;
    }

    public function getEmptyTooltip(): string|Markup|null
    {
        return $this->evaluate($this->emptyTooltip);
    }

    public function hasTooltip(): bool
    {
        return $this->tooltip !== null;
    }
}
