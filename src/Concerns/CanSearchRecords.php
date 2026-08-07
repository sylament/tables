<?php

declare(strict_types=1);

namespace Sylament\Tables\Concerns;

use Doctrine\ORM\QueryBuilder;
use Symfony\UX\LiveComponent\Attribute\LiveProp;

use function Sylament\Support\filled;

trait CanSearchRecords
{
    #[LiveProp(writable: true, dehydrateWith: 'dehydrateTableSearch', onUpdated: 'updatedTableSearch')]
    public ?string $tableSearch = '';

    /**
     * The wire value is the search input's own — the raw property, not the
     * trimmed `$value` the dehydration pass reads through `getTableSearch()`.
     */
    public function dehydrateTableSearch(?string $value): ?string
    {
        return $this->tableSearch;
    }

    public function updatedTableSearch(mixed $previousValue = null): void
    {
        if ($previousValue === $this->tableSearch) {
            return;
        }

        $this->tablePage = 1;
        $this->flushCachedTableRecords();
    }

    /**
     * Trimmed, blank-as-null, and raw user input beyond that — a `records()`
     * closure treats it exactly as it would a query parameter.
     */
    public function getTableSearch(): ?string
    {
        $search = mb_trim((string) $this->tableSearch);

        return $search === '' ? null : $search;
    }

    protected function applySearchToTableQuery(QueryBuilder $query): QueryBuilder
    {
        return $this->applyGlobalSearchToTableQuery($query);
    }

    protected function applyGlobalSearchToTableQuery(QueryBuilder $query): QueryBuilder
    {
        $search = $this->getTableSearch();

        if ($search === null) {
            return $query;
        }

        $table = $this->getTable();

        if ($table->hasSearchUsingCallback()) {
            $table->callSearchUsing($query, $search);

            return $query;
        }

        $searchWords = $table->shouldSplitSearchTerms() ? $this->extractTableSearchWords($search) : [$search];

        foreach ($searchWords as $searchWord) {
            $searchExpression = $query->expr()->orX();

            foreach ($table->getColumns() as $column) {
                if (!$column->isGloballySearchable()) {
                    continue;
                }

                $column->applySearchConstraint($query, $searchExpression, $searchWord);
            }

            $table->applyExtraSearchConstraints($query, $searchExpression, $searchWord);

            if ($searchExpression->count() > 0) {
                $query->andWhere($searchExpression);
            }
        }

        return $query;
    }

    /**
     * @return list<string>
     */
    protected function extractTableSearchWords(string $search): array
    {
        $normalized = preg_replace('/(\s|\x{3164}|\x{1160})+/u', ' ', $search) ?? $search;

        return array_values(array_filter(
            str_getcsv($normalized, separator: ' ', escape: '\\'),
            filled(...),
        ));
    }
}
