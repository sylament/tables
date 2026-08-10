<?php

declare(strict_types=1);

namespace Sylament\Tables\Tests\Fixtures\Records;

/**
 * A backed enum implementing none of support's state contracts — what a Doctrine
 * `enumType` column hands a cell when nobody has taught the enum how to label itself.
 */
enum Format: string
{
    case Digital = 'digital';

    case Vinyl = 'vinyl';
}
