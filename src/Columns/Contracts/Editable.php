<?php

declare(strict_types=1);

namespace Sylament\Tables\Columns\Contracts;

use Sylament\Schemas\Exceptions\ValidationException;

interface Editable
{
    /**
     * @throws ValidationException when the input is refused, keyed by the column name
     */
    public function validate(mixed $input): void;

    public function updateState(mixed $state): mixed;
}
