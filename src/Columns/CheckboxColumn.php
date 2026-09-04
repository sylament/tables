<?php

declare(strict_types=1);

namespace Sylament\Tables\Columns;

use Closure;
use Sylament\Forms\Components\Concerns\HasExtraInputAttributes;
use Sylament\Tables\Columns\Concerns\CanBeValidated;
use Sylament\Tables\Columns\Concerns\CanUpdateState;
use Sylament\Tables\Columns\Contracts\Editable;
use Symfony\Component\Validator\Constraints\Type;

class CheckboxColumn extends Column implements Editable
{
    use CanBeValidated;
    use CanUpdateState;
    use HasExtraInputAttributes;

    protected string|Closure|null $template = '@SylamentTables/partials/CheckboxColumn/index.html.twig';

    protected function setUp(): void
    {
        parent::setUp();

        $this->disabledClick();

        $this->constraint(new Type('bool'));
    }

    protected function hasBooleanClientState(): bool
    {
        return true;
    }
}
