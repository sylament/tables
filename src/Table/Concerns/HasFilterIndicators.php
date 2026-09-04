<?php

declare(strict_types=1);

namespace Sylament\Tables\Table\Concerns;

use Closure;
use Sylament\Tables\Filters\FilterIndicator;
use Sylament\Tables\Filters\Indicator;

trait HasFilterIndicators
{
    protected bool|Closure $areFilterIndicatorsHidden = false;

    public function hiddenFilterIndicators(bool|Closure $condition = true): static
    {
        $this->areFilterIndicatorsHidden = $condition;

        return $this;
    }

    /**
     * The active-filter chips — the global search chip first, then one per
     * column holding a term of its own, then every visible filter's — each
     * paired with the live action that removes it.
     *
     * @return list<FilterIndicator>
     */
    public function getFilterIndicators(): array
    {
        if ((bool) $this->evaluate($this->areFilterIndicatorsHidden)) {
            return [];
        }

        $indicators = [];

        $searchIndicator = $this->getSearchIndicator();

        if ($searchIndicator instanceof Indicator) {
            $indicators[] = new FilterIndicator($searchIndicator, 'resetTableSearch', []);
        }

        foreach ($this->getColumnSearchIndicators() as $columnName => $indicator) {
            $indicators[] = new FilterIndicator($indicator, 'resetTableColumnSearch', ['column' => $columnName]);
        }

        foreach ($this->getFilters() as $filterName => $filter) {
            foreach ($filter->getIndicators() as $indicator) {
                $indicators[] = new FilterIndicator($indicator, 'removeTableFilter', [
                    'filterName' => $filterName,
                    'field' => $indicator->getRemoveField(),
                ]);
            }
        }

        return $indicators;
    }
}
