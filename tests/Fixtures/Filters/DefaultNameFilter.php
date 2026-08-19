<?php

declare(strict_types=1);

namespace Sylament\Tables\Tests\Fixtures\Filters;

use Sylament\Tables\Filters\BaseFilter;

/**
 * A filter whose class supplies its own name, so `make()` can be called bare.
 */
final class DefaultNameFilter extends BaseFilter
{
    public static function getDefaultName(): string
    {
        return 'defaultNamed';
    }
}
