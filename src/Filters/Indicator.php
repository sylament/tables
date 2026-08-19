<?php

declare(strict_types=1);

namespace Sylament\Tables\Filters;

use Closure;
use Sylament\Support\Components\Component;
use Sylament\Support\Components\Concerns\HasColor;
use Twig\Markup;

class Indicator extends Component
{
    use HasColor;

    protected string $evaluationIdentifier = 'indicator';

    protected string|Markup|Closure $label = '';

    protected bool|Closure $isRemovable = true;

    protected string|Closure|null $removeField = null;

    public static function make(string|Markup|Closure $label): static
    {
        $indicator = static::newInstance();

        $indicator->label($label);

        $indicator->configure();

        return $indicator;
    }

    public function label(string|Markup|Closure $label): static
    {
        $this->label = $label;

        return $this;
    }

    public function getLabel(): string|Markup
    {
        return $this->evaluate($this->label);
    }

    public function removable(bool|Closure $condition = true): static
    {
        $this->isRemovable = $condition;

        return $this;
    }

    public function isRemovable(): bool
    {
        return (bool) $this->evaluate($this->isRemovable);
    }

    public function removeField(string|Closure|null $name): static
    {
        $this->removeField = $name;

        return $this;
    }

    public function getRemoveField(): ?string
    {
        return $this->evaluate($this->removeField);
    }
}
