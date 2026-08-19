<?php

declare(strict_types=1);

namespace Sylament\Tables\Enums;

enum FiltersLayout
{
    case Dropdown;

    case Modal;

    case AboveContent;

    case AboveContentCollapsible;

    case BelowContent;

    case BeforeContent;

    case BeforeContentCollapsible;

    case AfterContent;

    case AfterContentCollapsible;

    case Hidden;
}
