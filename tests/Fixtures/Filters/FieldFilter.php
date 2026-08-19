<?php

declare(strict_types=1);

namespace Sylament\Tables\Tests\Fixtures\Filters;

use Sylament\Forms\Components\Checkbox;
use Sylament\Tables\Filters\BaseFilter;

/**
 * A filter that builds a real form field, exercising `HasSchema`'s field arm.
 */
final class FieldFilter extends BaseFilter
{
    public function getFormField(): Checkbox
    {
        return Checkbox::make('isActive');
    }
}
