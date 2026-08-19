<?php

declare(strict_types=1);

namespace Sylament\Tables\Tests\Fixtures\Concerns;

use Closure;
use LogicException;
use Sylament\Tables\Concerns\HasTable;
use Sylament\Tables\Contracts\InteractsWithTable;
use Sylament\Tables\Table;

/**
 * A table host whose two filter-state readers answer from constructor-seeded
 * arrays rather than the live props `Concerns\HasFilters` reads. Overriding the
 * trait's pair lets a filter test state the slice it wants directly, without
 * standing up the filters form the real readers resolve names against.
 */
final class FilterStateHost implements InteractsWithTable
{
    use HasTable;

    /**
     * @param array<string, array<string, mixed>> $filterState
     * @param array<string, array<string, mixed>> $filterFormState
     */
    public function __construct(
        private readonly ?Closure $configureTable = null,
        private readonly array $filterState = [],
        private readonly array $filterFormState = [],
    ) {
    }

    public function table(Table $table): Table
    {
        if (!$this->configureTable instanceof Closure) {
            return $table;
        }

        $table = ($this->configureTable)($table);

        if (!$table instanceof Table) {
            throw new LogicException('The configureTable fixture closure must return a Table.');
        }

        return $table;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getTableFilterState(string $name): ?array
    {
        return $this->filterState[$name] ?? null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getTableFilterFormState(string $name): ?array
    {
        return $this->filterFormState[$name] ?? null;
    }
}
