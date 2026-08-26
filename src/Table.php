<?php

declare(strict_types=1);

namespace Sylament\Tables;

use Closure;
use Sylament\Schemas\Components\Concerns\CanAccessAssociations;
use Sylament\Schemas\Components\Concerns\CanAccessProperties;
use Sylament\Support\Components\ViewComponent;
use Sylament\Support\Evaluation\ClosureParameter;
use Sylament\Tables\Contracts\InteractsWithTable;
use Sylament\Tables\Table\Concerns\BelongsToLiveComponent;
use Sylament\Tables\Table\Concerns\CanPaginateRecords;
use Sylament\Tables\Table\Concerns\CanSearchRecords;
use Sylament\Tables\Table\Concerns\CanSortRecords;
use Sylament\Tables\Table\Concerns\HasActions;
use Sylament\Tables\Table\Concerns\HasAggregateValues;
use Sylament\Tables\Table\Concerns\HasBulkActions;
use Sylament\Tables\Table\Concerns\HasColumns;
use Sylament\Tables\Table\Concerns\HasEmptyState;
use Sylament\Tables\Table\Concerns\HasFilterIndicators;
use Sylament\Tables\Table\Concerns\HasFilters;
use Sylament\Tables\Table\Concerns\HasHeaderActions;
use Sylament\Tables\Table\Concerns\HasQuery;
use Sylament\Tables\Table\Concerns\HasRecordActions;
use Sylament\Tables\Table\Concerns\HasRecords;
use Sylament\Tables\Table\Concerns\HasToolbarActions;

class Table extends ViewComponent
{
    use BelongsToLiveComponent;
    use CanAccessAssociations;
    use CanAccessProperties;
    use CanPaginateRecords;
    use CanSearchRecords;
    use CanSortRecords;
    use HasActions;
    use HasAggregateValues;
    use HasBulkActions;
    use HasColumns;
    use HasEmptyState;
    use HasFilterIndicators;
    use HasFilters;
    use HasHeaderActions;
    use HasQuery;
    use HasRecordActions;
    use HasRecords;
    use HasToolbarActions;

    protected string $evaluationIdentifier = 'table';

    protected string|Closure|null $template = '@SylamentTables/partials/Table/index.html.twig';

    public static function make(InteractsWithTable $liveComponent): static
    {
        $table = static::newInstance();

        $table->liveComponent($liveComponent);

        $table->configure();

        return $table;
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->emptyStateDescription(fn (Table $table): ?string => $table->hasAction('create')
            ? sprintf('Create a %s to get started.', $table->getRecordLabel())
            : null);
    }

    protected function resolveDefaultClosureDependencyForEvaluationByName(string $parameterName): ?ClosureParameter
    {
        return match ($parameterName) {
            'liveComponent' => ClosureParameter::make($this->getLiveComponent()),
            default => parent::resolveDefaultClosureDependencyForEvaluationByName($parameterName),
        };
    }
}
