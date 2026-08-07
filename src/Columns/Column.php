<?php

declare(strict_types=1);

namespace Sylament\Tables\Columns;

use LogicException;
use Sylament\Schemas\Components\Concerns\CanAccessProperties;
use Sylament\Support\Components\ViewComponent;
use Sylament\Support\Evaluation\ClosureParameter;
use Sylament\Tables\Columns\Concerns\BelongsToTable;
use Sylament\Tables\Columns\Concerns\CanBeSearchable;
use Sylament\Tables\Columns\Concerns\CanBeSortable;
use Sylament\Tables\Columns\Concerns\HasCellState;
use Sylament\Tables\Columns\Concerns\HasLabel;
use Sylament\Tables\Columns\Concerns\HasName;
use Sylament\Tables\Columns\Concerns\HasRecord;
use Sylament\Tables\Columns\Concerns\InteractsWithTableQuery;

use function Sylament\Support\blank;

class Column extends ViewComponent
{
    use BelongsToTable;
    use CanAccessProperties;
    use CanBeSearchable;
    use CanBeSortable;
    use HasCellState;
    use HasLabel;
    use HasName;
    use HasRecord;
    use InteractsWithTableQuery;

    protected string $evaluationIdentifier = 'column';

    public static function make(?string $name = null): static
    {
        $name ??= static::getDefaultName();

        if (blank($name)) {
            throw new LogicException(sprintf('Column of class [%s] must have a unique name, passed to the [make()] method.', static::class));
        }

        $column = static::newInstance();

        $column->name($name);

        $column->configure();

        return $column;
    }

    public static function getDefaultName(): ?string
    {
        return null;
    }

    protected function resolveDefaultClosureDependencyForEvaluationByName(string $parameterName): ?ClosureParameter
    {
        return match ($parameterName) {
            'liveComponent' => ClosureParameter::make($this->getLiveComponent()),
            'record' => ClosureParameter::make($this->getRecord()),
            'state' => ClosureParameter::make($this->getState()),
            'table' => ClosureParameter::make($this->getTable()),
            default => parent::resolveDefaultClosureDependencyForEvaluationByName($parameterName),
        };
    }
}
