<?php

declare(strict_types=1);

namespace Sylament\Tables\Concerns;

use Doctrine\ORM\QueryBuilder;
use Sylament\Tables\Filters\Indicator;
use Sylament\Tables\Query\SearchTermSplitter;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\Metadata\UrlMapping;

trait CanSearchRecords
{
    #[LiveProp(writable: true, dehydrateWith: 'dehydrateTableSearch', onUpdated: 'updatedTableSearch', modifier: 'modifyTableSearchProp')]
    public ?string $tableSearch = '';

    public function modifyTableSearchProp(LiveProp $liveProp): LiveProp
    {
        $mapping = $this->getTableUrlMapping('search');

        return $mapping instanceof UrlMapping ? $liveProp->withUrl($mapping) : $liveProp;
    }

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

        if ($this->getTable()->shouldDeselectAllRecordsWhenFiltered()) {
            $this->deselectAllTableRecords();
        }

        $this->tablePage = 1;
        $this->flushCachedTableRecords();
        $this->persistTableSearchInSession();
    }

    /**
     * The clear button's channel to the same state change the prop's `onUpdated`
     * hook handles, so it deselects on the same terms.
     */
    #[LiveAction]
    public function resetTableSearch(): void
    {
        if ($this->getTable()->shouldDeselectAllRecordsWhenFiltered()) {
            $this->deselectAllTableRecords();
        }

        $this->tableSearch = '';
        $this->tablePage = 1;
        $this->flushCachedTableRecords();
        $this->persistTableSearchInSession();
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

    /**
     * The chip the indicators row renders for the global search term, or null
     * when nothing is searched for — Filament gates on a separate `hasSearch()`
     * where the nullable return says the same thing once.
     */
    public function getTableSearchIndicator(): ?Indicator
    {
        $search = $this->getTableSearch();

        if ($search === null) {
            return null;
        }

        return Indicator::make('Search: ' . $search);
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
        return SearchTermSplitter::split($search);
    }
}
