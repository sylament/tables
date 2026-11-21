<?php

declare(strict_types=1);

namespace Sylament\Tables\Columns\Concerns;

use Closure;
use Symfony\Contracts\Translation\TranslatableInterface;
use Twig\Markup;

use function Symfony\Component\String\u;

trait HasLabel
{
    protected string|Markup|TranslatableInterface|Closure|null $label = null;

    protected bool $shouldTranslateLabel = false;

    public function label(string|Markup|TranslatableInterface|Closure|null $label): static
    {
        $this->label = $label;

        return $this;
    }

    public function translateLabel(bool $shouldTranslateLabel = true): static
    {
        $this->shouldTranslateLabel = $shouldTranslateLabel;

        return $this;
    }

    public function getLabel(): string|Markup
    {
        $label = $this->evaluate($this->label) ?? $this->getDefaultLabel();

        if (is_string($label) && $this->shouldTranslateLabel) {
            return $this->getTranslator()->trans($label);
        }

        return $label instanceof TranslatableInterface ? (string) $this->translateText($label) : $label;
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
