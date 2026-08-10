<?php

declare(strict_types=1);

namespace Sylament\Tables\Colors\Components\TextColumnComponent;

use Sylament\Support\Colors\Color;
use Sylament\Support\Colors\ColorManager;
use Sylament\Support\Colors\Contracts\HasColor;
use Sylament\Support\Colors\Maps\ComponentColorMap;

class ItemComponent implements HasColor
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
            ->slot('text', surface: 'oklch(1 0 0)', fallback: 900)
            ->slot('dark:text', surface: $gray[900], maxShade: 600, shouldStartFromDarkest: true, fallback: 200)
            ->get();
    }
}
