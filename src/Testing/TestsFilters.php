<?php

declare(strict_types=1);

namespace Sylament\Tables\Testing;

use BackedEnum;
use Closure;
use LogicException;
use PHPUnit\Framework\Assert;
use Sylament\Tables\Contracts\InteractsWithTable;
use Sylament\Tables\Filters\BaseFilter;
use Sylament\Tables\Filters\SelectFilter;
use Sylament\Tables\Filters\TernaryFilter;

use function Sylament\Support\wrap;

/**
 * @phpstan-require-extends Testable
 */
trait TestsFilters
{
    /**
     * A ternary filter is given `value`, `true` when only the name is passed; a select
     * filter `value`, or `values` when it is multiple, with a record object sent as the
     * key the table host mints for it and an enum as its value; any other filter
     * `isActive` from data that is not an array, and an array as it is.
     */
    public function filterTable(string $name, mixed $data = null): static
    {
        $host = $this->getTestedTableHost();
        $name = $this->parseTestedTableFilterName($host, $name);

        $this->assertTableFilterExists($name);

        $filter = $host->getTable()->getFilter($name)
            ?? throw new LogicException(sprintf('The [%s] table has no filter named [%s].', $host::class, $name));

        if ($filter instanceof TernaryFilter) {
            if (($data === true) || (($data === null) && (func_num_args() === 1))) {
                $data = ['value' => true];
            } else {
                $data = ['value' => $data];
            }
        } elseif ($filter instanceof SelectFilter) {
            if ($filter->isMultiple()) {
                $data = ['values' => array_map(
                    fn (mixed $value): mixed => $this->getTestedTableFilterValue($host, $value),
                    wrap($data ?? []),
                )];
            } else {
                $data = ['value' => $this->getTestedTableFilterValue($host, $data)];
            }
        } elseif (!is_array($data)) {
            $data = ['isActive' => ($data === true) || ($data === null)];
        }

        $this->set('tableFilters', [...$this->getTestedTableFilterState($host), $filter->getName() => $data]);

        return $this;
    }

    public function resetTableFilters(): static
    {
        $this->call('resetTableFiltersForm');

        return $this;
    }

    public function removeTableFilter(string $filter, ?string $field = null): static
    {
        $this->call('removeTableFilter', [
            'filterName' => $this->parseTestedTableFilterName($this->getTestedTableHost(), $filter),
            'field' => $field,
        ]);

        return $this;
    }

    public function removeTableFilters(): static
    {
        $this->call('removeTableFilters');

        return $this;
    }

    /**
     * @param (Closure(BaseFilter): bool)|null $checkFilterUsing
     */
    public function assertTableFilterExists(string $name, ?Closure $checkFilterUsing = null): static
    {
        $host = $this->getTestedTableHost();
        $name = $this->parseTestedTableFilterName($host, $name);
        $filter = $host->getTable()->getFilter($name);
        $hostClass = $host::class;

        Assert::assertInstanceOf(
            BaseFilter::class,
            $filter,
            "Failed asserting that a table filter with name [{$name}] exists on the [{$hostClass}] component.",
        );

        if ($checkFilterUsing instanceof Closure) {
            Assert::assertTrue(
                $checkFilterUsing($filter),
                "Failed asserting that a table filter with name [{$name}] and provided configuration exists on the [{$hostClass}] component.",
            );
        }

        return $this;
    }

    /**
     * Through the host's own `parseTableFilterName()`, which turns a filter class into
     * its default name.
     */
    private function parseTestedTableFilterName(InteractsWithTable $host, string $name): string
    {
        return $host->parseTableFilterName($name);
    }

    /**
     * The applied filter state the re-hydrated host keeps, every filter's slice by its name.
     *
     * @return array<string, mixed>
     */
    private function getTestedTableFilterState(InteractsWithTable $host): array
    {
        if (!property_exists($host, 'tableFilters') || !is_array($host->tableFilters)) {
            throw new LogicException(sprintf('The [%s] component keeps no table filter state.', $host::class));
        }

        $state = [];

        foreach ($host->tableFilters as $name => $filterState) {
            $state[(string) $name] = $filterState;
        }

        return $state;
    }

    private function getTestedTableFilterValue(InteractsWithTable $host, mixed $value): mixed
    {
        if ($value instanceof BackedEnum) {
            return $value->value;
        }

        if (is_object($value)) {
            return $host->getTableRecordKey($value);
        }

        return $value;
    }
}
