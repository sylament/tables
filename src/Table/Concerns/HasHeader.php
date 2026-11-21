<?php

declare(strict_types=1);

namespace Sylament\Tables\Table\Concerns;

use Closure;
use Symfony\Contracts\Translation\TranslatableInterface;
use Twig\Markup;

trait HasHeader
{
    protected string|Markup|TranslatableInterface|Closure|null $heading = null;

    protected string|Markup|TranslatableInterface|Closure|null $description = null;

    public function heading(string|Markup|TranslatableInterface|Closure|null $heading): static
    {
        $this->heading = $heading;

        return $this;
    }

    public function description(string|Markup|TranslatableInterface|Closure|null $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function getHeading(): string|Markup|null
    {
        return $this->translateText($this->evaluate($this->heading));
    }

    public function getDescription(): string|Markup|null
    {
        return $this->translateText($this->evaluate($this->description));
    }
}
