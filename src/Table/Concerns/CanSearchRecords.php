<?php

declare(strict_types=1);

namespace Sylament\Tables\Table\Concerns;

use Closure;
use Doctrine\ORM\Query\Expr\Orx;
use Doctrine\ORM\QueryBuilder;
use Stringable;
use Sylament\Tables\Filters\Indicator;
use Sylament\Tables\Query\SearchConstraint;

use function Sylament\Support\filled;

trait CanSearchRecords
{
    protected ?bool $isSearchable = null;

    /**
     * @var array<string|Closure>
     */
    protected array $extraSearchableColumns = [];

    protected string|Closure|null $searchPlaceholder = null;

    protected ?string $searchDebounce = null;

    protected bool|Closure $isSearchOnBlur = false;

    protected bool|Closure $shouldSplitSearchTerms = true;

    protected ?Closure $searchUsing = null;

    protected bool|Closure $persistsSearchInSession = false;

    public function persistSearchInSession(bool|Closure $condition = true): static
    {
        $this->persistsSearchInSession = $condition;

        return $this;
    }

    public function persistsSearchInSession(): bool
    {
        return (bool) $this->evaluate($this->persistsSearchInSession);
    }

    /**
     * @param bool|array<string|Closure> $condition
     */
    public function searchable(bool|array $condition = true): static
    {
        if ($condition === true) {
            $this->isSearchable = true;
        } elseif (!$condition) {
            $this->isSearchable = false;
            $this->extraSearchableColumns = [];
        } else {
            $this->isSearchable = true;
            $this->extraSearchableColumns = $condition;
        }

        return $this;
    }

    public function isSearchable(): bool
    {
        if (is_bool($this->isSearchable)) {
            return $this->isSearchable;
        }

        if ($this->getExtraSearchableColumns() !== []) {
            return true;
        }

        foreach ($this->getColumns() as $column) {
            if ($column->isGloballySearchable()) {
                return true;
            }
        }

        return false;
    }

    public function searchPlaceholder(string|Closure|null $searchPlaceholder): static
    {
        $this->searchPlaceholder = $searchPlaceholder;

        return $this;
    }

    public function getSearchPlaceholder(): ?string
    {
        return $this->evaluate($this->searchPlaceholder);
    }

    public function searchDebounce(?string $debounce): static
    {
        $this->searchDebounce = $debounce;

        return $this;
    }

    public function getSearchDebounce(): string
    {
        return $this->searchDebounce ?? '500ms';
    }

    /**
     * The debounce as ux-live-component's `debounce(N)` modifier wants it —
     * Filament's duration strings (`500ms`, `1s`, bare numbers) translated,
     * anything unparseable falling back to 500.
     */
    public function getSearchDebounceMilliseconds(): int
    {
        if (preg_match('/^(\d+(?:\.\d+)?)\s*(ms|s)?$/', mb_trim($this->getSearchDebounce()), $matches) !== 1) {
            return 500;
        }

        $value = (float) $matches[1];

        return (int) round(($matches[2] ?? 'ms') === 's' ? $value * 1000 : $value);
    }

    public function searchOnBlur(bool|Closure $condition = true): static
    {
        $this->isSearchOnBlur = $condition;

        return $this;
    }

    public function isSearchOnBlur(): bool
    {
        return (bool) $this->evaluate($this->isSearchOnBlur);
    }

    /**
     * @return array<string|Closure>
     */
    public function getExtraSearchableColumns(): array
    {
        return $this->extraSearchableColumns;
    }

    public function splitSearchTerms(bool|Closure $condition = true): static
    {
        $this->shouldSplitSearchTerms = $condition;

        return $this;
    }

    public function shouldSplitSearchTerms(): bool
    {
        return (bool) $this->evaluate($this->shouldSplitSearchTerms);
    }

    public function searchUsing(?Closure $searchUsing): static
    {
        $this->searchUsing = $searchUsing;

        return $this;
    }

    public function hasSearchUsingCallback(): bool
    {
        return filled($this->searchUsing);
    }

    public function callSearchUsing(QueryBuilder $query, string $search): void
    {
        $this->evaluate($this->searchUsing, [
            'query' => $query,
            'search' => $search,
        ]);
    }

    public function applyExtraSearchConstraints(QueryBuilder $query, Orx $searchExpression, string $search): void
    {
        foreach (array_filter($this->getExtraSearchableColumns(), filled(...)) as $column) {
            if ($column instanceof Closure) {
                $constraint = $this->evaluateExtraSearchConstraint($column, $query, $search);

                if ($constraint instanceof QueryBuilder) {
                    continue;
                }

                if (is_string($constraint) || $constraint instanceof Stringable) {
                    $searchExpression->add($constraint);
                }

                continue;
            }

            $searchExpression->add(SearchConstraint::forColumn($query, $column, $search, forceCaseInsensitive: null));
        }
    }

    public function getSearchIndicator(): ?Indicator
    {
        return $this->getLiveComponent()->getTableSearchIndicator();
    }

    /**
     * Whatever an extra searchable column's closure returned — an expression to
     * add to the search group, or the builder it constrained itself, which joins
     * nothing.
     */
    protected function evaluateExtraSearchConstraint(Closure $column, QueryBuilder $query, string $search): mixed
    {
        return $this->evaluate($column, [
            'query' => $query,
            'search' => $search,
            'searchQuery' => $search,
        ]);
    }
}
