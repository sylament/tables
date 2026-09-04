<?php

declare(strict_types=1);

namespace Sylament\Tables\Columns;

use BackedEnum;
use Closure;
use Stringable;
use Sylament\Support\Components\Concerns\CanBeCopied;
use Sylament\Support\Components\Concerns\CanWrap;
use Traversable;
use Twig\Markup;

use function Sylament\Support\blank;
use function Sylament\Support\sanitize_css_color;
use function Sylament\Support\wrap;

class ColorColumn extends Column
{
    use CanBeCopied;
    use CanWrap;

    protected string|Closure|null $template = '@SylamentTables/partials/ColorColumn/index.html.twig';

    /**
     * The cell's swatches, one entry per state value: the sanitized CSS colour the
     * swatch paints and names itself with — null for a value no CSS colour syntax
     * admits — the copy control's three values when the item is copyable, the
     * per-item link where the column's `url()` reads the state, and the raw value
     * the per-item tooltip closure was handed.
     *
     * @return list<array{state: mixed, color: ?string, copyable: bool, copyableState: ?string, copyMessage: ?string, copyMessageDuration: ?int, url: ?string, tooltip: string|Markup|null}>
     */
    public function getStateItems(): array
    {
        return array_map(
            function (mixed $value): array {
                $isCopyable = $this->isCopyable($value);

                return [
                    'state' => $value,
                    'color' => $this->getSanitizedColor($value),
                    'copyable' => $isCopyable,
                    'copyableState' => $isCopyable ? ($this->getCopyableState($value) ?? $this->stringify($value)) : null,
                    'copyMessage' => $isCopyable ? $this->getCopyMessage($value) : null,
                    'copyMessageDuration' => $isCopyable ? $this->getCopyMessageDuration($value) : null,
                    'url' => $this->getUrl($value),
                    'tooltip' => $this->getTooltip($value),
                ];
            },
            $this->getWrappedState(),
        );
    }

    private function getSanitizedColor(mixed $value): ?string
    {
        if ($value instanceof BackedEnum) {
            $value = $value->value;
        }

        return sanitize_css_color(is_string($value) ? $value : null);
    }

    private function stringify(mixed $value): ?string
    {
        return is_scalar($value) || $value instanceof Stringable ? (string) $value : null;
    }

    /**
     * @return list<mixed>
     */
    private function getWrappedState(): array
    {
        $state = $this->getState();

        if (!is_array($state) && $state instanceof Traversable) {
            $state = iterator_to_array($state, false);
        }

        if (blank($state)) {
            return [];
        }

        return array_values(wrap($state));
    }
}
