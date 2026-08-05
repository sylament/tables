<?php

declare(strict_types=1);

namespace Sylament\Tables\Columns\Concerns;

use Closure;
use Twig\Markup;

use function Symfony\Component\String\u;

trait HasLabel
{
    protected string|Markup|Closure|null $label = null;

    public function label(string|Markup|Closure|null $label): static
    {
        $this->label = $label;

        return $this;
    }

    public function getLabel(): string|Markup
    {
        return $this->evaluate($this->label) ?? $this->getDefaultLabel();
    }

    protected function getDefaultLabel(): string
    {
        return (string) u($this->getName())
            ->beforeLast('.')
            ->afterLast('.')
            ->kebab()
            ->replace('-', ' ')
            ->title();
    }
}
