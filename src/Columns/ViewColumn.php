<?php

declare(strict_types=1);

namespace Sylament\Tables\Columns;

use LogicException;

class ViewColumn extends Column
{
    public function getTemplate(): string
    {
        return parent::getTemplate() ?? throw new LogicException(sprintf(
            'The [%s] column has no template. Call template() on it, or declare a $template property on a subclass.',
            static::class,
        ));
    }
}
