<?php

declare(strict_types=1);

namespace Sylament\Tables\Filters\Concerns;

use Closure;
use Sylament\Tables\Filters\Indicator;
use Twig\Markup;

use function Sylament\Support\blank;
use function Sylament\Support\wrap;

trait HasIndicators
{
    /**
     * @var array<Indicator|string|Markup>|Indicator|string|Markup|Closure|null
     */
    protected array|Indicator|string|Markup|Closure|null $indicateUsing = null;

    protected Indicator|string|Markup|Closure|null $indicator = null;

    public function indicator(Indicator|string|Markup|Closure|null $indicator): static
    {
        $this->indicator = $indicator;

        return $this;
    }

    public function indicateUsing(?Closure $callback): static
    {
        $this->indicateUsing = $callback;

        return $this;
    }

    /**
     * A string array key names the field the chip removes.
     *
     * @return array<array-key, Indicator>
     */
    public function getIndicators(): array
    {
        $state = $this->getState();

        $indicators = $this->evaluate($this->indicateUsing, ['data' => $state, 'state' => $state]);

        if (blank($indicators)) {
            return [];
        }

        $resolved = [];

        foreach (wrap($indicators) as $field => $indicator) {
            if (!$indicator instanceof Indicator) {
                $indicator = Indicator::make($indicator);
            }

            if (is_string($field)) {
                $indicator = $indicator->removeField($field);
            }

            $resolved[$field] = $indicator;
        }

        return $resolved;
    }

    public function getIndicator(): Indicator|string|Markup
    {
        $state = $this->getState();

        return $this->evaluate($this->indicator, ['data' => $state, 'state' => $state]) ?? $this->getLabel();
    }
}
