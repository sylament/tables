<?php

declare(strict_types=1);

namespace Sylament\Tables\Columns\Summarizers\Concerns;

use Closure;
use Symfony\Contracts\Translation\TranslatableInterface;
use Twig\Markup;

trait HasLabel
{
    protected bool|Closure $isLabelHidden = false;

    protected string|Markup|TranslatableInterface|Closure|null $label = null;

    protected bool $shouldTranslateLabel = false;

    public function hiddenLabel(bool|Closure $condition = true): static
    {
        $this->isLabelHidden = $condition;

        return $this;
    }

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

    /**
     * The default label where none was set, and null where a blank one was — the
     * way a caller drops the default a concrete summarizer carries.
     *
     * The blank test is written out rather than read off `blank()`, which models
     * as a null check alone at level max and is impossible past the null arm, and
     * it reads the translated text, so a message resolving to blank drops the
     * label as a blank string does. A string label is a key under `translateLabel()`,
     * and the default label is never translated, as in Filament.
     */
    public function getLabel(): string|Markup|null
    {
        $label = $this->evaluate($this->label);

        if ($label === null) {
            return $this->translateText($this->getDefaultLabel());
        }

        $label = is_string($label) && $this->shouldTranslateLabel
            ? $this->getTranslator()->trans($label)
            : $this->translateText($label);

        return mb_trim((string) $label) === '' ? null : $label;
    }

    public function isLabelHidden(): bool
    {
        return (bool) $this->evaluate($this->isLabelHidden);
    }

    public function getDefaultLabel(): string|Markup|TranslatableInterface|null
    {
        return null;
    }
}
