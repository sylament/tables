<?php

declare(strict_types=1);

namespace Sylament\Tables\Filters;

use Closure;
use Doctrine\ORM\QueryBuilder;
use LogicException;
use Sylament\Forms\Components\Select;
use Sylament\Tables\Filters\Concerns\HasOptions;
use Sylament\Tables\Filters\Concerns\HasPlaceholder;
use Sylament\Tables\Query\ColumnPathResolver;

use function Sylament\Support\filled;
use function Sylament\Support\wrap;

class SelectFilter extends BaseFilter
{
    use HasOptions;
    use HasPlaceholder;

    protected string|Closure|null $attribute = null;

    protected bool|Closure $canSelectPlaceholder = true;

    protected bool|Closure $isMultiple = false;

    protected bool|Closure $isStatic = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resetState(fn (): array => $this->isMultiple() ? ['values' => []] : ['value' => null]);

        $this->placeholder('All');

        $this->indicateUsing(function (array $state): array {
            $labels = $this->getStateLabels(
                $this->isMultiple() ? ($state['values'] ?? null) : ($state['value'] ?? null),
            );

            if ($labels === []) {
                return [];
            }

            $indicator = $this->getIndicator();

            if (!$indicator instanceof Indicator) {
                $indicator = Indicator::make("{$indicator}: {$this->joinStateLabels($labels)}");
            }

            return [$indicator];
        });
    }

    public function attribute(string|Closure|null $name): static
    {
        $this->attribute = $name;

        return $this;
    }

    public function getAttribute(): string
    {
        return $this->evaluate($this->attribute) ?? $this->getName();
    }

    public function static(bool|Closure $condition = true): static
    {
        $this->isStatic = $condition;

        return $this;
    }

    public function multiple(bool|Closure $condition = true): static
    {
        $this->isMultiple = $condition;

        return $this;
    }

    public function isMultiple(): bool
    {
        return (bool) $this->evaluate($this->isMultiple);
    }

    public function selectablePlaceholder(bool|Closure $condition = true): static
    {
        $this->canSelectPlaceholder = $condition;

        return $this;
    }

    public function canSelectPlaceholder(): bool
    {
        return (bool) $this->evaluate($this->canSelectPlaceholder);
    }

    public function getFormField(): Select
    {
        $field = Select::make($this->isMultiple() ? 'values' : 'value')
            ->label($this->getLabel())
            ->multiple($this->isMultiple())
            ->placeholder($this->getPlaceholder())
            ->selectablePlaceholder($this->canSelectPlaceholder())
            ->options(fn (): array => $this->getOptions());

        $defaultState = $this->getDefaultState();

        if (filled($defaultState)) {
            $field->default($defaultState);
        }

        return $field;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function apply(QueryBuilder $query, array $data = []): QueryBuilder
    {
        if ($this->evaluate($this->isStatic)) {
            return $query;
        }

        if ($this->hasQueryModificationCallback()) {
            return parent::apply($query, $data);
        }

        $isMultiple = $this->isMultiple();

        $values = $isMultiple ? ($data['values'] ?? null) : ($data['value'] ?? null);

        if (array_filter(wrap($values), filled(...)) === []) {
            return $query;
        }

        $path = $this->getAttributePath($query);

        if ($isMultiple) {
            $parameter = $this->bindQueryParameter($query, array_values(wrap($values)));

            $query->andWhere($query->expr()->in($path, ":{$parameter}"));

            return $query;
        }

        $parameter = $this->bindQueryParameter($query, $values);

        $query->andWhere($query->expr()->eq($path, ":{$parameter}"));

        return $query;
    }

    public function getActiveCount(): int
    {
        $state = $this->getState();

        $value = $this->isMultiple() ? ($state['values'] ?? []) : ($state['value'] ?? null);

        return filled($value) ? 1 : 0;
    }

    /**
     * @throws LogicException when the attribute is a dotted path whose head the
     *                        builder declares no alias for, which a filter has
     *                        no join of its own to reach
     */
    protected function getAttributePath(QueryBuilder $query): string
    {
        $attribute = $this->getAttribute();
        $alias = str_contains($attribute, '.') ? explode('.', $attribute, 2)[0] : null;

        if ($alias !== null && !in_array($alias, $query->getAllAliases(), true)) {
            throw new LogicException(sprintf('Cannot resolve [%s] for this filter: [%s] is not an alias on the builder, and a filter joins nothing of its own — where sorting and search resolve a dotted name against the mapping, a filter does not. Join and alias the relation where the table query is declared and name that alias here, or take over the comparison with the filter\'s own query() closure.', $attribute, $alias));
        }

        return ColumnPathResolver::resolve($query, $attribute);
    }

    /**
     * Binds a value under a name unique on this builder and returns that name,
     * so nothing a filter compares against is ever interpolated into the DQL.
     */
    protected function bindQueryParameter(QueryBuilder $query, mixed $value): string
    {
        $parameter = 'sylament_filter_' . count($query->getParameters());

        $query->setParameter($parameter, $value);

        return $parameter;
    }

    /**
     * @return list<string>
     */
    protected function getStateLabels(mixed $values): array
    {
        $options = [];

        foreach ($this->getOptions() as $value => $label) {
            if (!is_array($label)) {
                $options[$value] = $label;

                continue;
            }

            foreach ($label as $groupedValue => $groupedLabel) {
                $options[$groupedValue] = $groupedLabel;
            }
        }

        $labels = [];

        foreach (wrap($values) as $value) {
            if (!is_string($value) && !is_int($value)) {
                continue;
            }

            if (!array_key_exists($value, $options)) {
                continue;
            }

            $labels[] = $options[$value];
        }

        return $labels;
    }

    /**
     * @param list<string> $labels
     */
    protected function joinStateLabels(array $labels): string
    {
        if (count($labels) < 2) {
            return implode('', $labels);
        }

        $last = array_pop($labels);

        return implode(', ', $labels) . ' & ' . $last;
    }
}
