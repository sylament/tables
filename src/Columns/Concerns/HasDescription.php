<?php

declare(strict_types=1);

namespace Sylament\Tables\Columns\Concerns;

use Closure;
use Twig\Markup;

trait HasDescription
{
    protected string|Markup|Closure|null $descriptionAbove = null;

    protected string|Markup|Closure|null $descriptionBelow = null;

    public function description(string|Markup|Closure|null $description, string|Closure|null $position = 'below'): static
    {
        if ($position === 'above') {
            $this->descriptionAbove = $description;
        } else {
            $this->descriptionBelow = $description;
        }

        return $this;
    }

    public function getDescriptionAbove(): string|Markup|null
    {
        return $this->evaluate($this->descriptionAbove);
    }

    public function getDescriptionBelow(): string|Markup|null
    {
        return $this->evaluate($this->descriptionBelow);
    }

    public function hasDescription(): bool
    {
        return $this->descriptionAbove !== null || $this->descriptionBelow !== null;
    }
}
