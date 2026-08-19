<?php

declare(strict_types=1);

namespace Sylament\Tables\Concerns;

use Doctrine\ORM\Query\Expr\OrderBy;
use Doctrine\ORM\QueryBuilder;
use Sylament\Tables\Columns\Column;
use Sylament\Tables\Query\ColumnPathResolver;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;

use function Sylament\Support\blank;

trait CanSortRecords
{
    /**
     * `column:direction`, or a bare column meaning ascending; null when
     * unsorted. Mutated only by `sortTable()`, so it stays checksummed.
     */
    #[LiveProp]
    public ?string $tableSort = null;

    /**
     * A request naming a column that is not declared sortable is ignored, so
     * the `sortColumn` injection only ever carries declared-sortable names.
     */
    #[LiveAction]
    public function sortTable(#[LiveArg] string|int|null $column = null, #[LiveArg] ?string $direction = null): void
    {
        $column = $column === null ? null : (string) $column;

        if ($column === null || $this->getTable()->getSortableVisibleColumn($column) === null) {
            return;
        }

        if (!in_array($direction, ['asc', 'desc', null], true)) {
            return;
        }

        if ($column === $this->getTableSortColumn()) {
            $direction ??= match ($this->getTableSortDirection()) {
                'asc' => 'desc',
                'desc' => null,
                default => 'asc',
            };
        } else {
            $direction ??= 'asc';
        }

        $this->tableSort = $direction === null ? null : "{$column}:{$direction}";
        $this->tablePage = 1;
        $this->flushCachedTableRecords();
    }

    public function getTableSortColumn(): ?string
    {
        if (blank($this->tableSort)) {
            return null;
        }

        return explode(':', (string) $this->tableSort, 2)[0];
    }

    public function getTableSortDirection(): ?string
    {
        if (blank($this->tableSort)) {
            return null;
        }

        $parts = explode(':', (string) $this->tableSort, 2);

        if (!isset($parts[1])) {
            return 'asc';
        }

        return match ($parts[1]) {
            'asc', 'desc' => $parts[1],
            default => null,
        };
    }

    protected function applySortingToTableQuery(QueryBuilder $query): QueryBuilder
    {
        $table = $this->getTable();
        $tableSortColumn = $this->getTableSortColumn();

        if ($tableSortColumn !== null) {
            $column = $table->getSortableVisibleColumn($tableSortColumn);

            if ($column instanceof Column) {
                $column->applySort($query, $this->getTableSortDirection() === 'desc' ? 'desc' : 'asc');
            }
        }

        $sortDirection = ($table->getDefaultSortDirection() ?? $this->getTableSortDirection()) === 'desc' ? 'desc' : 'asc';
        $defaultSort = $table->getDefaultSort($query, $sortDirection);

        if (is_string($defaultSort) && $defaultSort !== $tableSortColumn) {
            $defaultSortColumn = $table->getSortableVisibleColumn($defaultSort);

            if ($defaultSortColumn instanceof Column) {
                $defaultSortColumn->applySort($query, $sortDirection);
            } else {
                $query->addOrderBy(ColumnPathResolver::resolveOrderable($query, $defaultSort), $sortDirection);
            }
        }

        if ($defaultSort instanceof QueryBuilder) {
            $query = $defaultSort;
        }

        if (!$table->hasDefaultKeySort()) {
            return $query;
        }

        return $this->applyDefaultKeySort($query, $sortDirection);
    }

    private function applyDefaultKeySort(QueryBuilder $query, string $direction): QueryBuilder
    {
        $recordClass = $query->getRootEntities()[0] ?? null;
        $rootAlias = $query->getRootAliases()[0] ?? null;

        if ($recordClass === null || $rootAlias === null) {
            return $query;
        }

        $metadata = $query->getEntityManager()->getClassMetadata($recordClass);

        if ($metadata->isIdentifierComposite) {
            return $query;
        }

        $identifierField = $metadata->getSingleIdentifierFieldName();
        $qualifiedKey = "{$rootAlias}.{$identifierField}";

        /** @var list<OrderBy> $orderings */
        $orderings = $query->getDQLPart('orderBy');

        foreach ($orderings as $ordering) {
            foreach ($ordering->getParts() as $part) {
                $sortedPath = explode(' ', $part, 2)[0];

                if ($sortedPath === $qualifiedKey) {
                    return $query;
                }
            }
        }

        return $query->addOrderBy($qualifiedKey, $direction);
    }
}
