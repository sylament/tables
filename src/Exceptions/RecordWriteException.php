<?php

declare(strict_types=1);

namespace Sylament\Tables\Exceptions;

use LogicException;

/**
 * An editable column could not write its state onto the record: the accessor
 * refused the value the client posted, or the path it names. It is its own class
 * so the host can file that refusal against the cell while every other
 * `LogicException` the write path raises — a misconfigured column, a throwing
 * `updateStateUsing()` — still propagates.
 */
class RecordWriteException extends LogicException
{
}
