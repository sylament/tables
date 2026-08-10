<?php

declare(strict_types=1);

namespace Sylament\Tables\Colors\Components\TextColumnComponent\ItemComponent;

use Sylament\Support\Colors\Contracts\HasColor;
use Sylament\Support\Colors\Contracts\HasDefaultGrayColor;

class IconComponent implements HasColor, HasDefaultGrayColor
{
    /**
     * @param array<int, string> $color
     * @return array<string, int>
     */
    public function getColorMap(array $color): array
    {
        return [];
    }
}
