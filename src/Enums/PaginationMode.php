<?php

declare(strict_types=1);

namespace Sylament\Tables\Enums;

enum PaginationMode
{
    case Default;

    case Simple;

    case Cursor;
}
