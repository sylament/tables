<?php

declare(strict_types=1);

namespace Sylament\Tables\Tests\Fixtures\Records;

use BackedEnum;
use Sylament\Support\Contracts\HasColor;
use Sylament\Support\Contracts\HasIcon;
use Sylament\Support\Contracts\HasLabel;
use Twig\Markup;

enum Status: string implements HasColor, HasIcon, HasLabel
{
    case Draft = 'draft';

    case Published = 'published';

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Published => 'success',
        };
    }

    public function getIcon(): string|BackedEnum|Markup|null
    {
        return match ($this) {
            self::Draft => 'heroicons:chevron-down',
            self::Published => 'heroicons:x-mark',
        };
    }

    public function getLabel(): string|Markup|null
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Published => 'Published',
        };
    }
}
