<?php

declare(strict_types=1);

namespace Sylament\Tables\Exceptions;

use LogicException;
use Throwable;

/**
 * An editable column could not write its state onto the record: the accessor
 * refused the value the client posted or the path it names, or the write would
 * have changed the record's identifier. It is its own class so the host can file
 * that refusal against the cell while every other `LogicException` the write
 * path raises — a misconfigured column, a throwing `updateStateUsing()` — still
 * propagates.
 *
 * Its own message is the developer's, naming the column and the reason. The
 * user's text travels beside it as the invalid message, as Symfony Form's
 * `TransformationFailedException` carries one: the cell paints it when set, and
 * the validators catalogue's generic refusal otherwise. The host logs the
 * developer's message only for a refusal that carries no invalid message.
 */
class RecordWriteException extends LogicException
{
    public function __construct(
        string $message = '',
        int $code = 0,
        ?Throwable $previous = null,
        private readonly ?string $invalidMessage = null,
    ) {
        parent::__construct($message, $code, $previous);
    }

    public function getInvalidMessage(): ?string
    {
        return $this->invalidMessage;
    }
}
