<?php

declare(strict_types=1);

namespace Sylament\Tables\Columns;

use BackedEnum;
use Closure;
use LogicException;
use Sylament\Support\Components\Concerns\CanWrap;
use Sylament\Support\Components\Concerns\HasFontFamily;
use Sylament\Support\Components\Concerns\HasLineClamp;
use Sylament\Support\Components\Concerns\HasWeight;
use Sylament\Support\Enums\TextSize;
use Sylament\Tables\Columns\Concerns\CanFormatState;
use Sylament\Tables\Columns\Concerns\HasColor;
use Sylament\Tables\Columns\Concerns\HasDescription;
use Sylament\Tables\Columns\Concerns\HasIcon;
use Sylament\Tables\Columns\Concerns\HasIconColor;
use Traversable;
use Twig\Markup;

use function Sylament\Support\blank;
use function Sylament\Support\wrap;

class TextColumn extends Column
{
    use CanFormatState;
    use CanWrap;
    use HasColor;
    use HasDescription;
    use HasFontFamily;
    use HasIcon;
    use HasIconColor;
    use HasLineClamp;
    use HasWeight;

    protected string|Closure|null $template = '@SylamentTables/partials/TextColumn/index.html.twig';

    protected bool|Closure $isBadge = false;

    protected TextSize|string|Closure|null $size = null;

    public function badge(bool|Closure $condition = true): static
    {
        $this->isBadge = $condition;

        return $this;
    }

    public function isBadge(): bool
    {
        return (bool) $this->evaluate($this->isBadge);
    }

    public function size(TextSize|string|Closure|null $size): static
    {
        $this->size = $size;

        return $this;
    }

    public function getSize(mixed $state): TextSize|string
    {
        $size = $this->evaluate($this->size, [
            'state' => $state,
        ]);

        if (blank($size)) {
            return TextSize::Small;
        }

        if (is_string($size)) {
            $size = TextSize::tryFrom($size) ?? $size;
        }

        if ($size === 'base') {
            return TextSize::Medium;
        }

        return $size;
    }

    /**
     * The class fragment templates emit for the size: `sy-size-<value>` for a `TextSize`
     * case, a custom string as-is — so templates never type-sniff the enum-vs-string union.
     */
    public function getSizeClass(mixed $state): string
    {
        $size = $this->getSize($state);

        if ($size instanceof BackedEnum) {
            return 'sy-size-' . $size->value;
        }

        return $size;
    }

    /**
     * The cell's values, paired with the content each renders. A multi-value state
     * collapses to one comma-joined entry unless the column is a badge, matching
     * Filament's default — so a colour closure on a collapsed cell sees the joined
     * string, not an item.
     *
     * @return array<int, array{state: mixed, content: string|Markup|null}>
     */
    public function getStateItems(): array
    {
        $state = $this->getState();

        if (! is_array($state) && $state instanceof Traversable) {
            $state = iterator_to_array($state, false);
        }

        if (blank($state)) {
            return [];
        }

        $values = array_values(wrap($state));

        if (count($values) > 1 && ! $this->isBadge()) {
            $joined = implode(', ', array_map(
                fn (mixed $value): string => (string) $this->formatStateForItem($value),
                $values,
            ));

            return [['state' => $joined, 'content' => $joined]];
        }

        return array_map(
            fn (mixed $value): array => ['state' => $value, 'content' => $this->formatStateForItem($value)],
            $values,
        );
    }

    /**
     * Narrows `formatState()`'s `mixed` return to the `string|Markup|null` its pipeline
     * actually produces.
     */
    private function formatStateForItem(mixed $value): string|Markup|null
    {
        $formatted = $this->formatState($value);

        if ($formatted === null || is_string($formatted) || $formatted instanceof Markup) {
            return $formatted;
        }

        throw new LogicException(sprintf('formatState() for column [%s] must return a string, Markup, or null; %s given.', $this->getName(), get_debug_type($formatted)));
    }
}
