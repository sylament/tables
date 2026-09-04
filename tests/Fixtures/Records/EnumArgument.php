<?php

declare(strict_types=1);

namespace Sylament\Tables\Tests\Fixtures\Records;

/**
 * The backed-enum arm of the money and numeric arguments: a currency code and a
 * number locale, so both unwraps are driven from one fixture.
 */
enum EnumArgument: string
{
    case Currency = 'eur';

    case Locale = 'de_DE';
}
