<?php

declare(strict_types=1);

namespace Sylament\Tables\Columns\Summarizers;

use Closure;
use Doctrine\ORM\QueryBuilder;
use LogicException;
use Sylament\Support\Components\Concerns\HasExtraAttributes;
use Sylament\Support\Components\ViewComponent;
use Sylament\Support\Evaluation\ClosureParameter;
use Sylament\Tables\Columns\Summarizers\Concerns\BelongsToColumn;
use Sylament\Tables\Columns\Summarizers\Concerns\CanBeHidden;
use Sylament\Tables\Columns\Summarizers\Concerns\CanFormatState;
use Sylament\Tables\Columns\Summarizers\Concerns\HasLabel;
use Sylament\Tables\Columns\Summarizers\Concerns\InteractsWithTableQuery;
use Sylament\Tables\Query\AggregateDeclaration;
use Sylament\Tables\Query\SummaryQuery;

/**
 * Concrete, because `Summarizer::make()->using(…)` is documented API: a bare
 * summarizer whose figure a closure computes is a supported way to summarize
 * anything the five concrete ones cannot.
 */
class Summarizer extends ViewComponent
{
    use BelongsToColumn;
    use CanBeHidden;
    use CanFormatState;
    use HasExtraAttributes;
    use HasLabel;
    use InteractsWithTableQuery;

    public const string DEFAULT_TEMPLATE = '@SylamentTables/partials/Summarizer/index.html.twig';

    protected string $evaluationIdentifier = 'summarizer';

    protected string|Closure|null $defaultTemplate = self::DEFAULT_TEMPLATE;

    protected ?string $id = null;

    /**
     * @var array<string, mixed>
     */
    protected array $selectedState = [];

    protected ?Closure $using = null;

    public static function make(?string $id = null): static
    {
        $summarizer = static::newInstance();

        $summarizer->id($id);

        $summarizer->configure();

        return $summarizer;
    }

    public function id(?string $id): static
    {
        $this->id = $id;

        return $this;
    }

    public function getId(): ?string
    {
        return $this->id;
    }

    public function using(?Closure $using): static
    {
        $this->using = $using;

        return $this;
    }

    /**
     * The row the batched select read, keyed by alias — handed to every summarizer
     * of the render, each of which looks its own alias up.
     *
     * @param array<string, mixed> $state
     */
    public function selectedState(array $state): static
    {
        $this->selectedState = $state;

        return $this;
    }

    /**
     * The batched value where the select carried one, otherwise the figure this
     * summarizer reads for itself.
     *
     * The short-circuit asks whether the alias is in the row rather than whether the
     * value is filled, so an aggregate that came back null over an empty set is not
     * re-run to arrive at the same null.
     *
     * A `using()` closure answers ahead of every refusal the aggregate path makes —
     * it is the way out each of their messages names — and is handed the column's own
     * name beside the query, as upstream, since the expression it needs is its own to
     * build.
     *
     * @throws LogicException on a `records()` table, whose rows no query produced,
     *                        and on a column reading a relationship aggregate
     */
    public function getState(): mixed
    {
        if ($this->hasSelectedState()) {
            return $this->getSelectedState();
        }

        $column = $this->getColumn();
        $attribute = $column->getName();
        $query = $this->getQuery();

        if (!$query instanceof QueryBuilder) {
            throw new LogicException(sprintf('Cannot summarize [%s]: summaries read their figures from the table query, and this table lists records() rows the toolkit never queried. Use query() or relationship(), or drop the summarizer.', $attribute));
        }

        $query = clone $query;

        if ($this->hasQueryModification()) {
            $scoped = $this->evaluateQueryModification($query, $attribute);

            $query = $scoped instanceof QueryBuilder ? $scoped : $query;
        }

        if ($this->using instanceof Closure) {
            return $this->evaluate($this->using, [
                'attribute' => $attribute,
                'query' => $query,
            ]);
        }

        if ($column->getCellAggregateDeclaration() instanceof AggregateDeclaration) {
            throw new LogicException(sprintf('Cannot summarize [%s]: the column reads a relationship aggregate, which is filled in per record from a side store and which DQL cannot aggregate again. Take the summary over with Summarizer::using().', $attribute));
        }

        return $this->summarize($query, SummaryQuery::qualifyAttribute($query, $attribute));
    }

    public function getSelectedState(): mixed
    {
        return null;
    }

    /**
     * Whether the batched row carried this summarizer's alias at all — the
     * distinction between a figure of null and no figure.
     */
    public function hasSelectedState(): bool
    {
        return false;
    }

    public function summarize(QueryBuilder $query, string $attribute): mixed
    {
        return null;
    }

    /**
     * The aggregates this summarizer contributes to the one batched select, keyed
     * by the alias its value comes back under. Empty is the opt-out: a summarizer
     * that emits nothing runs its own query instead.
     *
     * @return array<string, string> alias ⇒ DQL aggregate expression
     */
    public function getSelectStatements(string $column): array
    {
        return [];
    }

    /**
     * @return array<array-key, mixed>
     */
    public function getExtraTemplateData(): array
    {
        return [
            'state' => $this->formatState($this->getState()),
        ];
    }

    /**
     * A numeric value at the summarizer boundary: DQL types every aggregate but
     * `COUNT` as a string and converts none of them, so PostgreSQL hands back
     * `"82.5000000000000000"` where SQLite hands back `82.5`.
     */
    protected static function toNumber(mixed $value): int|float|null
    {
        if (is_int($value) || is_float($value)) {
            return $value;
        }

        if (!is_string($value) || !is_numeric($value)) {
            return null;
        }

        return preg_match('/^[+-]?\d++$/', $value) === 1 ? (int) $value : (float) $value;
    }

    protected function resolveDefaultClosureDependencyForEvaluationByName(string $parameterName): ?ClosureParameter
    {
        return match ($parameterName) {
            'liveComponent' => ClosureParameter::make($this->getLiveComponent()),
            'query' => ClosureParameter::make($this->getQuery()),
            'table' => ClosureParameter::make($this->getTable()),
            default => parent::resolveDefaultClosureDependencyForEvaluationByName($parameterName),
        };
    }
}
