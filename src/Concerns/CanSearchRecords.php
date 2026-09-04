<?php

declare(strict_types=1);

namespace Sylament\Tables\Concerns;

use Doctrine\ORM\QueryBuilder;
use Stringable;
use Sylament\Tables\Columns\Column;
use Sylament\Tables\Filters\Indicator;
use Sylament\Tables\Query\SearchTermSplitter;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\Attribute\PostHydrate;
use Symfony\UX\LiveComponent\Metadata\UrlMapping;
use Symfony\UX\TwigComponent\Attribute\PostMount;

trait CanSearchRecords
{
    #[LiveProp(writable: true, dehydrateWith: 'dehydrateTableSearch', onUpdated: 'updatedTableSearch', modifier: 'modifyTableSearchProp')]
    public ?string $tableSearch = '';

    /**
     * The per-column terms, nested exactly as the browser writes them — a dotted
     * column name lands as nested keys — and seeded with every individually
     * searchable column, because the client refuses a model path the props do
     * not carry. A blank stays a blank rather than being forgotten, for the same
     * reason.
     *
     * @var array<string, mixed>
     */
    #[LiveProp(writable: true, hydrateWith: 'hydrateTableColumnSearches', dehydrateWith: 'dehydrateTableColumnSearches', modifier: 'modifyTableColumnSearchesProp')]
    public array $tableColumnSearches = [];

    /**
     * @var array<string, mixed>|null
     */
    private ?array $hydratedTableColumnSearches = null;

    public function modifyTableSearchProp(LiveProp $liveProp): LiveProp
    {
        $mapping = $this->getTableUrlMapping('search');

        return $mapping instanceof UrlMapping ? $liveProp->withUrl($mapping) : $liveProp;
    }

    public function modifyTableColumnSearchesProp(LiveProp $liveProp): LiveProp
    {
        $mapping = $this->getTableUrlMapping('columnSearches');

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

    /**
     * The bag as it arrived, kept from the first call only: the hydrator hands
     * over the original value first and any update after it, so the first call
     * is the pre-update state the post-hydrate compare needs.
     *
     * @return array<string, mixed>
     */
    public function hydrateTableColumnSearches(mixed $value): array
    {
        $bag = is_array($value) ? self::castTableColumnSearches($value) : [];

        $this->hydratedTableColumnSearches ??= $bag;

        return $bag;
    }

    /**
     * The wire carries the nested, untrimmed bag the inputs model — the property
     * itself — never the flat trimmed map `getTableColumnSearches()` hands the
     * query lane, which is what the property accessor would read in the
     * property's place.
     *
     * @return array<string, mixed>
     */
    public function dehydrateTableColumnSearches(mixed $value): array
    {
        return $this->tableColumnSearches;
    }

    #[PostMount(priority: 3)]
    public function mountTableColumnSearches(): void
    {
        $this->tableColumnSearches = $this->seedTableColumnSearches(self::castTableColumnSearches($this->tableColumnSearches));
    }

    /**
     * A nested model write never fires the prop's own update hook, so the change
     * is read off the bag itself: what arrived against what the client sent.
     */
    #[PostHydrate(priority: -5)]
    public function afterTableColumnSearchesHydrated(): void
    {
        $this->tableColumnSearches = $this->seedTableColumnSearches(self::castTableColumnSearches($this->tableColumnSearches));

        if ($this->tableColumnSearches === $this->seedTableColumnSearches(self::castTableColumnSearches($this->hydratedTableColumnSearches ?? []))) {
            return;
        }

        $this->handleTableColumnSearchUpdates();
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
     * The chip's channel, over a client-supplied column name — so the write is
     * gated on the seed's own predicate. Keeping a blank rather than forgetting
     * it means an ungated write would *add* a key permanently, and a dotted one
     * would replace a real leaf with a map.
     */
    #[LiveAction]
    public function resetTableColumnSearch(#[LiveArg] string $column): void
    {
        $resolved = $this->getTable()->getColumn($column);

        if (!$resolved instanceof Column || !$resolved->isIndividuallySearchable()) {
            return;
        }

        $this->tableColumnSearches = self::writeTableColumnSearch($this->tableColumnSearches, $column, '');

        $this->handleTableColumnSearchUpdates();
    }

    #[LiveAction]
    public function resetTableColumnSearches(): void
    {
        $this->tableColumnSearches = $this->seedTableColumnSearches([]);

        $this->handleTableColumnSearchUpdates();
    }

    /**
     * One column's stored term, raw and untrimmed — what its input renders as
     * `value`, so a trimmed value never fights the keystroke that produced it.
     */
    public function getTableColumnSearch(string $column): string
    {
        return self::readTableColumnSearch($this->tableColumnSearches, $column) ?? '';
    }

    /**
     * The bag as a flat, dotted, trimmed map — `['artist' => ['name' => ' x ']]`
     * reads `['artist.name' => 'x']` — which is the form the query lane, the
     * indicators and the `columnSearches` injection all consume.
     *
     * @return array<string, string>
     */
    public function getTableColumnSearches(): array
    {
        return self::flattenTableColumnSearches($this->tableColumnSearches);
    }

    /**
     * @return array<string, Indicator> keyed by column name
     */
    public function getTableColumnSearchIndicators(): array
    {
        $table = $this->getTable();
        $indicators = [];

        foreach ($this->getTableColumnSearches() as $columnName => $search) {
            if ($search === '') {
                continue;
            }

            $column = $table->getColumn($columnName);

            if (!$column instanceof Column || $column->isHidden() || !$column->isIndividuallySearchable()) {
                continue;
            }

            $indicators[$columnName] = Indicator::make(sprintf('%s: %s', $column->getLabel(), $search));
        }

        return $indicators;
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
        return $this->applyGlobalSearchToTableQuery($this->applyColumnSearchesToTableQuery($query));
    }

    protected function applyColumnSearchesToTableQuery(QueryBuilder $query): QueryBuilder
    {
        $table = $this->getTable();
        $shouldSplitByDefault = $table->shouldSplitSearchTerms();

        foreach ($this->getTableColumnSearches() as $columnName => $search) {
            if ($search === '') {
                continue;
            }

            $column = $table->getColumn($columnName);

            if (!$column instanceof Column || $column->isHidden() || !$column->isIndividuallySearchable()) {
                continue;
            }

            $searchWords = ($column->shouldSplitIndividualSearchTerms() ?? $shouldSplitByDefault)
                ? $this->extractTableSearchWords($search)
                : [$search];

            foreach ($searchWords as $searchWord) {
                $searchExpression = $query->expr()->orX();

                $column->applySearchConstraint($query, $searchExpression, $searchWord);

                if ($searchExpression->count() > 0) {
                    $query->andWhere($searchExpression);
                }
            }
        }

        return $query;
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
                if ($column->isHidden()) {
                    continue;
                }

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

    protected function handleTableColumnSearchUpdates(): void
    {
        if ($this->getTable()->shouldDeselectAllRecordsWhenFiltered()) {
            $this->deselectAllTableRecords();
        }

        $this->tablePage = 1;
        $this->flushCachedTableRecords();
        $this->persistTableColumnSearchesInSession();
    }

    /**
     * @param array<string, mixed> $searches
     *
     * @return array<string, mixed>
     */
    protected function seedTableColumnSearches(array $searches): array
    {
        foreach ($this->getTable()->getColumns() as $column) {
            if (!$column->isIndividuallySearchable()) {
                continue;
            }

            $searches = self::writeTableColumnSearch($searches, $column->getName(), self::readTableColumnSearch($searches, $column->getName()) ?? '');
        }

        return $searches;
    }

    /**
     * @return list<string>
     */
    protected function extractTableSearchWords(string $search): array
    {
        return SearchTermSplitter::split($search);
    }

    /**
     * Every leaf a string, every nested map kept — the shape the session and the
     * client both hand back is the one the seed and the flatten expect. A key
     * that is not a string names no column and is dropped rather than cast: PHP
     * re-coerces a numeric string back to an `int` array key, so `(string) $key`
     * would leave one in the bag for `Table::getColumn(string)` to fatal on.
     *
     * @param array<array-key, mixed> $searches
     *
     * @return array<string, mixed>
     */
    private static function castTableColumnSearches(array $searches): array
    {
        $cast = [];

        foreach ($searches as $key => $value) {
            if (!is_string($key)) {
                continue;
            }

            $cast[$key] = match (true) {
                is_array($value) => self::castTableColumnSearches($value),
                is_scalar($value), $value instanceof Stringable => (string) $value,
                default => '',
            };
        }

        return $cast;
    }

    /**
     * A non-string key is dropped here too, so a bag assigned server-side rather
     * than cast on its way in cannot reach `Table::getColumn(string)` as an int.
     *
     * @param array<array-key, mixed> $searches
     *
     * @return array<string, string>
     */
    private static function flattenTableColumnSearches(array $searches, string $prefix = ''): array
    {
        $flattened = [];

        foreach ($searches as $key => $value) {
            if (!is_string($key)) {
                continue;
            }

            $path = $prefix === '' ? $key : $prefix . '.' . $key;

            if (is_array($value)) {
                $flattened = [...$flattened, ...self::flattenTableColumnSearches($value, $path)];

                continue;
            }

            $flattened[$path] = mb_trim(is_scalar($value) || $value instanceof Stringable ? (string) $value : '');
        }

        return $flattened;
    }

    /**
     * @param array<string, mixed> $searches
     */
    private static function readTableColumnSearch(array $searches, string $column): ?string
    {
        $node = $searches;

        foreach (explode('.', $column) as $segment) {
            if (!is_array($node) || !array_key_exists($segment, $node)) {
                return null;
            }

            $node = $node[$segment];
        }

        return is_string($node) ? $node : null;
    }

    /**
     * @param array<string, mixed> $searches
     *
     * @return array<string, mixed>
     */
    private static function writeTableColumnSearch(array $searches, string $column, string $value): array
    {
        $segments = explode('.', $column);
        $head = array_shift($segments);

        if ($segments === []) {
            $searches[$head] = $value;

            return $searches;
        }

        $child = $searches[$head] ?? null;
        $searches[$head] = self::writeTableColumnSearch(self::castTableColumnSearches(is_array($child) ? $child : []), implode('.', $segments), $value);

        return $searches;
    }
}
