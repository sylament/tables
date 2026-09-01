<?php

declare(strict_types=1);

namespace Sylament\Tables\Table\Concerns;

use Closure;
use Twig\Markup;

trait HasHeader
{
    protected string|Markup|Closure|null $heading = null;

    protected string|Markup|Closure|null $description = null;

    public function heading(string|Markup|Closure|null $heading): static
    {
        $this->heading = $heading;

        return $this;
    }

    public function description(string|Markup|Closure|null $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function getHeading(): string|Markup|null
    {
        return $this->evaluate($this->heading);
    }

    public function getDescription(): string|Markup|null
    {
        return $this->evaluate($this->description);
    }
}
