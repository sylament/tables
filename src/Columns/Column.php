<?php

declare(strict_types=1);

namespace Sylament\Tables\Columns;

use Closure;
use LogicException;
use Sylament\Actions\Action;
use Sylament\Schemas\Components\Concerns\CanAccessProperties;
use Sylament\Support\Components\Concerns\CanGrow;
use Sylament\Support\Components\Concerns\CanWrapHeader;
use Sylament\Support\Components\Concerns\HasAlignment;
use Sylament\Support\Components\Concerns\HasExtraAttributes;
use Sylament\Support\Components\Concerns\HasPlaceholder;
use Sylament\Support\Components\Concerns\HasVerticalAlignment;
use Sylament\Support\Components\Concerns\HasWidth;
use Sylament\Support\Components\ViewComponent;
use Sylament\Support\Evaluation\ClosureParameter;
use Sylament\Tables\Columns\Concerns\BelongsToTable;
use Sylament\Tables\Columns\Concerns\CanAggregateRelatedRecords;
use Sylament\Tables\Columns\Concerns\CanBeDisabled;
use Sylament\Tables\Columns\Concerns\CanBeHidden;
use Sylament\Tables\Columns\Concerns\CanBeHiddenResponsively;
use Sylament\Tables\Columns\Concerns\CanBeInline;
use Sylament\Tables\Columns\Concerns\CanBeSearchable;
use Sylament\Tables\Columns\Concerns\CanBeSortable;
use Sylament\Tables\Columns\Concerns\CanBeSummarized;
use Sylament\Tables\Columns\Concerns\CanBeToggled;
use Sylament\Tables\Columns\Concerns\CanCallAction;
use Sylament\Tables\Columns\Concerns\CanOpenUrl;
use Sylament\Tables\Columns\Concerns\HasCellState;
use Sylament\Tables\Columns\Concerns\HasExtraCellAttributes;
use Sylament\Tables\Columns\Concerns\HasExtraHeaderAttributes;
use Sylament\Tables\Columns\Concerns\HasLabel;
use Sylament\Tables\Columns\Concerns\HasName;
use Sylament\Tables\Columns\Concerns\HasRecord;
use Sylament\Tables\Columns\Concerns\HasRowLoopObject;
use Sylament\Tables\Columns\Concerns\HasTooltip;
use Sylament\Tables\Columns\Concerns\InteractsWithTableQuery;

use function Sylament\Support\blank;
use function Symfony\Component\String\u;

class Column extends ViewComponent
{
    use BelongsToTable;
    use CanAccessProperties;
    use CanAggregateRelatedRecords;
    use CanBeDisabled;
    use CanBeHidden;
    use CanBeHiddenResponsively;
    use CanBeInline;
    use CanBeSearchable;
    use CanBeSortable;
    use CanBeSummarized;
    use CanBeToggled;
    use CanCallAction;
    use CanGrow;
    use CanOpenUrl;
    use CanWrapHeader;
    use HasAlignment;
    use HasCellState;
    use HasExtraAttributes;
    use HasExtraCellAttributes;
    use HasExtraHeaderAttributes;
    use HasLabel;
    use HasName;
    use HasPlaceholder;
    use HasRecord;
    use HasRowLoopObject;
    use HasTooltip;
    use HasVerticalAlignment;
    use HasWidth;
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

    public function getCellClassName(): string
    {
        return 'sy-ta-cell-' . $this->getKebabCasedName();
    }

    public function getHeaderCellClassName(): string
    {
        return 'sy-ta-header-cell-' . $this->getKebabCasedName();
    }

    /**
     * Which wiring the cell wrapper owes this column's action — `action` for a
     * mountable descriptor, `closure` for one the host evaluates, null for
     * none. Twig cannot type-sniff the union, so the choice is made here.
     */
    public function getActionKind(): ?string
    {
        $action = $this->getAction();

        return match (true) {
            $action instanceof Action => 'action',
            $action instanceof Closure => 'closure',
            default => null,
        };
    }

    protected function resolveDefaultClosureDependencyForEvaluationByName(string $parameterName): ?ClosureParameter
    {
        return match ($parameterName) {
            'liveComponent' => ClosureParameter::make($this->getLiveComponent()),
            'record' => ClosureParameter::make($this->getRecord()),
            'rowLoop' => ClosureParameter::make($this->getRowLoop()),
            'state' => ClosureParameter::make($this->getState()),
            'table' => ClosureParameter::make($this->getTable()),
            default => parent::resolveDefaultClosureDependencyForEvaluationByName($parameterName),
        };
    }

    /**
     * Each dotted segment camel-kebabbed on its own, the dots kept — so
     * `author.name` and `authorName` stay distinct class names.
     */
    private function getKebabCasedName(): string
    {
        return implode('.', array_map(
            static fn (string $segment): string => (string) u($segment)->camel()->kebab(),
            explode('.', $this->getName()),
        ));
    }
}
