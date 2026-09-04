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
use Symfony\UX\LiveComponent\Metadata\UrlMapping;
use Symfony\UX\TwigComponent\Attribute\PostMount;

use function Sylament\Support\blank;

trait CanSortRecords
{
    /**
     * `column:direction`, or a bare column meaning ascending; null when
     * unsorted. Non-writable, so the client reaches it only through
     * `sortTable()` — the query string and the session reach it at mount.
     */
    #[LiveProp(modifier: 'modifyTableSortProp')]
    public ?string $tableSort = null;

    public function modifyTableSortProp(LiveProp $liveProp): LiveProp
    {
        $mapping = $this->getTableUrlMapping('sort');

        return $mapping instanceof UrlMapping ? $liveProp->withUrl($mapping) : $liveProp;
    }

    /**
     * The mount edge of the same check `sortTable()` runs, below the session
     * read so that it covers both writers that bypass the action: a query string
     * naming anything the table does not offer, and a session written against a
     * table that has since dropped the column.
     *
     * The cleared value is written back, so a stale entry is forgotten rather
     * than re-read and re-cleared on every later visit.
     */
    #[PostMount(priority: 5)]
    public function healSeededTableSort(): void
    {
        if (blank($this->tableSort)) {
            return;
        }

        $column = $this->getTableSortColumn();

        if ($column !== null
            && $this->getTableSortDirection() !== null
            && $this->getTable()->getSortableVisibleColumn($column) !== null) {
            return;
        }

        $this->tableSort = null;
        $this->persistTableSortInSession();
    }

    /**
     * Both writers of the property validate, so the `sortColumn` injection
     * carries a name the table declared sortable when it was last written: a
     * request naming anything else is ignored here, and a seeded value naming
     * anything else is cleared at mount.
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
        $this->persistTableSortInSession();
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

        // Under `groupsOnly()` the group's own order is the only one there is:
        // no record row renders, so no user sort, default sort or tiebreaker
        // means anything — and this wins over reordering, as Filament has it.
        if ($table->isGroupsOnly()) {
            return $query;
        }

        // A drag orders the rows itself, so the reorder column replaces every
        // ordering the table would otherwise apply — the user's sort, the
        // default sort and the identifier tiebreaker alike.
        if ($this->isTableReordering()) {
            return $query->orderBy(
                ColumnPathResolver::resolveOrderable($query, (string) $table->getReorderColumn()),
                $table->getReorderDirection() === 'desc' ? 'desc' : 'asc',
            );
        }

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
