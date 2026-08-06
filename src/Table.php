<?php

declare(strict_types=1);

namespace Sylament\Tables;

use Closure;
use Sylament\Support\Components\ViewComponent;
use Sylament\Support\Evaluation\ClosureParameter;
use Sylament\Tables\Contracts\InteractsWithTable;
use Sylament\Tables\Table\Concerns\BelongsToLiveComponent;
use Sylament\Tables\Table\Concerns\CanPaginateRecords;
use Sylament\Tables\Table\Concerns\CanSearchRecords;
use Sylament\Tables\Table\Concerns\CanSortRecords;
use Sylament\Tables\Table\Concerns\HasActions;
use Sylament\Tables\Table\Concerns\HasColumns;
use Sylament\Tables\Table\Concerns\HasEmptyState;
use Sylament\Tables\Table\Concerns\HasRecordActions;
use Sylament\Tables\Table\Concerns\HasRecords;

class Table extends ViewComponent
{
    use BelongsToLiveComponent;
    use CanPaginateRecords;
    use CanSearchRecords;
    use CanSortRecords;
    use HasActions;
    use HasColumns;
    use HasEmptyState;
    use HasRecordActions;
    use HasRecords;

    protected string $evaluationIdentifier = 'table';

    protected string|Closure|null $template = '@SylamentTables/partials/Table/index.html.twig';

    public static function make(InteractsWithTable $liveComponent): static
    {
        $table = static::newInstance();

        $table->liveComponent($liveComponent);

        $table->configure();

        return $table;
    }

    protected function resolveDefaultClosureDependencyForEvaluationByName(string $parameterName): ?ClosureParameter
    {
        return match ($parameterName) {
            'liveComponent' => ClosureParameter::make($this->getLiveComponent()),
            default => parent::resolveDefaultClosureDependencyForEvaluationByName($parameterName),
        };
    }
}
