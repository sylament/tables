<?php

declare(strict_types=1);

namespace Sylament\Tables\Enums;

enum RecordActionsPosition
{
    case AfterCells;

    case AfterColumns;

    case AfterContent;

    case BeforeCells;

    case BeforeColumns;
}
