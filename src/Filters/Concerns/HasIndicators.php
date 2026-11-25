<?php

declare(strict_types=1);

namespace Sylament\Tables\Filters\Concerns;

use Closure;
use Sylament\Tables\Filters\Indicator;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Contracts\Translation\TranslatableInterface;
use Twig\Markup;

use function Sylament\Support\blank;
use function Sylament\Support\wrap;

trait HasIndicators
{
    /**
     * @var array<Indicator|string|Markup|TranslatableInterface>|Indicator|string|Markup|TranslatableInterface|Closure|null
     */
    protected array|Indicator|string|Markup|TranslatableInterface|Closure|null $indicateUsing = null;

    protected Indicator|string|Markup|TranslatableInterface|Closure|null $indicator = null;

    public function indicator(Indicator|string|Markup|TranslatableInterface|Closure|null $indicator): static
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

    /**
     * The chip a filter answers by default, `<indicator>: <state>` as one message so a locale words the
     * pair itself; an indicator that is already a chip is kept, and a `Markup` one reads as its text.
     */
    protected function makeStateIndicator(Indicator|string|Markup $indicator, string $state): Indicator
    {
        if ($indicator instanceof Indicator) {
            return $indicator;
        }

        return Indicator::make(new TranslatableMessage('table.filters.state_indicator', [
            'indicator' => $indicator instanceof Markup ? html_entity_decode(strip_tags((string) $indicator), ENT_QUOTES | ENT_HTML5, 'UTF-8') : $indicator,
            'state' => $state,
        ], 'sylament-tables'));
    }

    public function getIndicator(): Indicator|string|Markup
    {
        $state = $this->getState();

        $indicator = $this->evaluate($this->indicator, ['data' => $state, 'state' => $state]) ?? $this->getLabel();

        return $indicator instanceof TranslatableInterface ? (string) $this->translateText($indicator) : $indicator;
    }
}
