<?php

declare(strict_types=1);

namespace Sylament\Tables\Tests\Fixtures\Concerns;

use Closure;
use LogicException;
use Sylament\Tables\Concerns\HasTable;
use Sylament\Tables\Contracts\InteractsWithTable;
use Sylament\Tables\Table;
use Symfony\UX\LiveComponent\Metadata\UrlMapping;

/**
 * A host that answers the URL-mapping hook with whatever the test hands it, and
 * — when `$refusesTable` is set — refuses to build a table at all, so a modifier
 * that reached `getTable()` would fail loudly instead of silently memoising a
 * table built before `#[PostHydrate]` ran.
 */
final class UrlMappedTableHost implements InteractsWithTable
{
    use HasTable;

    /**
     * @param array<string, UrlMapping> $mappings
     */
    public function __construct(
        private readonly array $mappings = [],
        private readonly ?string $identifier = null,
        private readonly bool $refusesTable = false,
        private readonly ?Closure $configureTable = null,
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

    public function getTableQueryStringIdentifier(): ?string
    {
        return $this->identifier;
    }

    protected function getTableUrlMapping(string $prop): ?UrlMapping
    {
        return $this->mappings[$prop] ?? null;
    }

    protected function makeTable(): Table
    {
        if ($this->refusesTable) {
            throw new LogicException('This host refuses to build a table.');
        }

        return Table::make($this);
    }
}
