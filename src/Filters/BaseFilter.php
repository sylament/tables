<?php

declare(strict_types=1);

namespace Sylament\Tables\Filters;

use LogicException;
use Sylament\Support\Components\Component;
use Sylament\Support\Evaluation\ClosureParameter;
use Sylament\Tables\Filters\Concerns\BelongsToTable;
use Sylament\Tables\Filters\Concerns\CanBeHidden;
use Sylament\Tables\Filters\Concerns\CanResetState;
use Sylament\Tables\Filters\Concerns\CanSpanColumns;
use Sylament\Tables\Filters\Concerns\HasColumns;
use Sylament\Tables\Filters\Concerns\HasDefaultState;
use Sylament\Tables\Filters\Concerns\HasIndicators;
use Sylament\Tables\Filters\Concerns\HasLabel;
use Sylament\Tables\Filters\Concerns\HasName;
use Sylament\Tables\Filters\Concerns\HasSchema;
use Sylament\Tables\Filters\Concerns\InteractsWithTableQuery;

use function Sylament\Support\blank;

class BaseFilter extends Component
{
    use BelongsToTable;
    use CanBeHidden;
    use CanResetState;
    use CanSpanColumns;
    use HasColumns;
    use HasDefaultState;
    use HasIndicators;
    use HasLabel;
    use HasName;
    use HasSchema;
    use InteractsWithTableQuery;

    protected string $evaluationIdentifier = 'filter';

    public static function make(?string $name = null): static
    {
        $name ??= static::getDefaultName();

        if (blank($name)) {
            throw new LogicException(sprintf('Filter of class [%s] must have a unique name, passed to the [make()] method.', static::class));
        }

        $filter = static::newInstance();

        $filter->name($name);

        $filter->configure();

        return $filter;
    }

    public static function getDefaultName(): ?string
    {
        return null;
    }

    public function getActiveCount(): int
    {
        return count($this->getIndicators());
    }

    protected function resolveDefaultClosureDependencyForEvaluationByName(string $parameterName): ?ClosureParameter
    {
        return match ($parameterName) {
            'liveComponent' => ClosureParameter::make($this->getLiveComponent()),
            'table' => ClosureParameter::make($this->getTable()),
            default => parent::resolveDefaultClosureDependencyForEvaluationByName($parameterName),
        };
    }
}
