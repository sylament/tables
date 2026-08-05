<?php

declare(strict_types=1);

namespace Sylament\Tables\Columns;

use Closure;
use Sylament\Tables\Columns\Concerns\CanFormatState;

class TextColumn extends Column
{
    use CanFormatState;

    protected string|Closure|null $template = '@SylamentTables/partials/TextColumn/index.html.twig';
}
