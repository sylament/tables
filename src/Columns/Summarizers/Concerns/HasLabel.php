<?php

declare(strict_types=1);

namespace Sylament\Tables\Columns\Summarizers\Concerns;

use Closure;
use Twig\Markup;

trait HasLabel
{
    protected bool|Closure $isLabelHidden = false;

    protected string|Markup|Closure|null $label = null;

    public function hiddenLabel(bool|Closure $condition = true): static
    {
        $this->isLabelHidden = $condition;

        return $this;
    }

    public function label(string|Markup|Closure|null $label): static
    {
        $this->label = $label;

        return $this;
    }

    /**
     * The default label where none was set, and null where a blank one was — the
     * way a caller drops the default a concrete summarizer carries.
     *
     * The blank test is written out rather than read off `blank()`, which models
     * as a null check alone at level max and is impossible past the null arm.
     */
    public function getLabel(): string|Markup|null
    {
        $label = $this->evaluate($this->label);

        if ($label === null) {
            return $this->getDefaultLabel();
        }

        return mb_trim((string) $label) === '' ? null : $label;
    }

    public function isLabelHidden(): bool
    {
        return (bool) $this->evaluate($this->isLabelHidden);
    }

    public function getDefaultLabel(): ?string
    {
        return null;
    }
}
