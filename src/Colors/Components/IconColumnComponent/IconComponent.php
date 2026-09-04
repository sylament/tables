<?php

declare(strict_types=1);

namespace Sylament\Tables\Colors\Components\IconColumnComponent;

use Sylament\Support\Colors\Color;
use Sylament\Support\Colors\ColorManager;
use Sylament\Support\Colors\Contracts\HasColor;
use Sylament\Support\Colors\Contracts\HasDefaultGrayColor;
use Sylament\Support\Colors\Maps\ComponentColorMap;

/**
 * The icons are the cell's only content, so each shade has to clear the 3:1 a
 * non-text element owes its surface rather than the 4.5:1 of body text.
 *
 * @see https://www.w3.org/WAI/WCAG21/Understanding/non-text-contrast.html
 */
class IconComponent implements HasColor, HasDefaultGrayColor
{
    public function __construct(
        private readonly ColorManager $colors,
    ) {
    }

    /**
     * @param array<int, string> $color
     * @return array<string, int>
     */
    public function getColorMap(array $color): array
    {
        $gray = $this->colors->getColor('gray') ?? Color::Zinc;

        return ComponentColorMap::make($color)
            ->slot('text', surface: 'oklch(1 0 0)', minRatio: Color::WCAG_AA_NON_TEXT, fallback: 900)
            ->slot('dark:text', surface: $gray[900], minRatio: Color::WCAG_AA_NON_TEXT, maxShade: 600, shouldStartFromDarkest: true, fallback: 200)
            ->get();
    }
}
