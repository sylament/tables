<?php

declare(strict_types=1);

namespace Sylament\Tables\Tests\Fixtures\Filters;

use Sylament\Tables\Filters\BaseFilter;
use Sylament\Tables\Filters\Concerns\HasOptions;
use Sylament\Tables\Filters\Concerns\HasPlaceholder;

/**
 * The two concerns `BaseFilter` does not compose — `SelectFilter` will.
 */
final class OptionsFilter extends BaseFilter
{
    use HasOptions;
    use HasPlaceholder;
}
