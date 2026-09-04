<?php

declare(strict_types=1);

namespace Sylament\Tables\Contracts;

use Doctrine\ORM\QueryBuilder;
use Sylament\Schemas\Schema;
use Sylament\Support\Pagination\Paginator;
use Sylament\Tables\Filters\Indicator;
use Sylament\Tables\Grouping\Group;
use Sylament\Tables\Table;
use Twig\Markup;

interface InteractsWithTable
{
    public function getTable(): Table;

    /**
     * The morph-key prefix every row and cell of this host's table is stamped with.
     */
    public function getTableHostId(): string;

    /**
     * @return array<array-key, object|array<string, mixed>>|Paginator keyed by record key
     */
    public function getTableRecords(): array|Paginator;

    /**
     * @return object|array<string, mixed>|null
     */
    public function getTableRecord(?string $key): object|array|null;

    /**
     * Drop the memoised page of records so the next read re-queries — what a
     * write from a mounted action needs before the table re-renders.
     */
    public function flushCachedTableRecords(): void;

    /**
     * @param object|array<string, mixed> $record
     */
    public function getTableRecordKey(object|array $record): string;

    /**
     * Whether the table is in drag-reorder mode — re-gated by the definition, so
     * it is false on a table that declares no reorder column. Read by the
     * partial to suppress everything a drag must not compete with.
     */
    public function isTableReordering(): bool;

    /**
     * Whether the deferred window has closed — always true on a table that does
     * not defer, so `Table::isLoaded()` answers for every table. Read by the
     * partial as the gate on every records-touching call it makes.
     */
    public function isTableLoaded(): bool;

    public function getTableSortColumn(): ?string;

    public function getTableSortDirection(): ?string;

    /**
     * The group the table's records are grouped by, or null when it groups
     * none. Read by the partial for the header rows, and by
     * `Table::getGrouping()`.
     */
    public function getTableGrouping(): ?Group;

    /**
     * The group id the client has selected, before any of it is resolved — null
     * where nothing is selected, and a name no registered group carries where
     * the client wrote one. Read by the grouping selector, which shows the
     * selection rather than the group in force, as vendor's does.
     */
    public function getTableGroupingColumn(): ?string;

    /**
     * Whether the grouping order is ascending or descending, or null where the
     * grouping carries no direction of its own — which is not "ungrouped": the
     * query lane falls back to ascending. Read by the grouping selector.
     */
    public function getTableGroupingDirection(): ?string;

    /**
     * Where each rendered record sits in its group, keyed by record key and
     * empty when the table is not grouped. Read by the partial once per row, in
     * place of tracking the previous record across its loop. The key type is
     * `getTableRecords()`'s and for its reason: PHP coerces a numeric-string
     * record key to an int, so a consumer must not assume `array_keys()` hands
     * back strings.
     *
     * @return array<array-key, array{key: ?string, title: string|Markup|null, description: string|Markup|null, isFirstOfGroup: bool, isLastOfGroup: bool, indexInGroup: int}>
     */
    public function getTableRecordGroupBoundaries(): array;

    public function getTablePaginator(): ?Paginator;

    public function getTableRecordsPerPage(): int|string|null;

    /**
     * The page the table is showing, clamped to a page whose offset is still an
     * integer. Read by `TextColumn::rowIndex()`, which turns a row's position on
     * the page into its position in the whole set.
     */
    public function getTablePage(): int;

    /**
     * The refusal a `updateTableColumnState()` write left against one cell on
     * this request, or null. Read by an editable column's partial, which paints
     * the message under the control and echoes the rejected input back.
     *
     * @return array{message: string, input: mixed}|null
     */
    public function getTableColumnStateError(string $column, string $recordKey): ?array;

    /**
     * The global-search chip for the indicators row, or null when nothing is
     * searched for. Read by `Table::getSearchIndicator()`.
     */
    public function getTableSearchIndicator(): ?Indicator;

    /**
     * The dataset the all-table summary row reads — filters, the search and the
     * per-column searches applied, unsorted and unpaged. Null off the query lane.
     */
    public function getAllTableSummaryQuery(): ?QueryBuilder;

    /**
     * The same dataset narrowed to the page's own record keys, or null where there
     * is no page to narrow to. Read by the summary band for its page row.
     */
    public function getPageTableSummaryQuery(): ?QueryBuilder;

    /**
     * The same dataset narrowed to the group one record belongs to, or null on a
     * table that groups nothing. Read by the summary band once per group header the
     * page carries.
     *
     * @param object|array<string, mixed> $record
     */
    public function getGroupedTableSummaryQuery(object|array $record): ?QueryBuilder;

    /**
     * One batched read of every batchable summarizer over the query given, keyed by
     * the alias each summarizer looks its own figure up under. Read by the summary
     * band once per row it renders.
     *
     * @return array<string, mixed>
     */
    public function getTableSummarySelectedState(?QueryBuilder $query): array;

    /**
     * The one schema holding every visible filter's fields, one `Group` per
     * filter. Read by the filters panel; a host that is not a schema host
     * throws rather than answering.
     */
    public function getTableFiltersForm(): Schema;

    /**
     * Whether the record under `$key` is selected — tracking-aware, so it
     * answers the deselection bag while the selection is inverted. Read by the
     * partial to render each checkbox's `checked`.
     */
    public function isTableRecordSelected(string $key): bool;

    /**
     * Whether anything is selected at all. Read by the partial to stamp the
     * server-side `hidden` on the selection banner and the bulk-only triggers.
     */
    public function hasSelectedTableRecords(): bool;

    /**
     * The selected records, re-derived server-side from the client's keys:
     * hydrated records, or their keys alone under `fetchSelectedRecords(false)`.
     * Read by a bulk action through this contract.
     *
     * @return list<object|array<string, mixed>>|list<string>
     */
    public function getSelectedTableRecords(bool $shouldFetchSelectedRecords = true): array;

    /**
     * The table's query narrowed to the selection, for a bulk action that
     * writes without hydrating. Query lane only — a records() table throws.
     */
    public function getSelectedTableRecordsQuery(bool $shouldFetchSelectedRecords = true): QueryBuilder;

    /**
     * Every selectable record key inside one group, for a group checkbox's
     * tri-state and its toggle. `$group` is the group's string key, nullable
     * because a record whose group column is blank forms its own group. Read by
     * the partial once per rendered group header.
     *
     * @return list<string>
     */
    public function getGroupedSelectableTableRecordKeys(?string $group): array;

    /**
     * How many records the user could select in total, or null where the table
     * carries no total. Read by the partial as the banner's ceiling.
     */
    public function getAllSelectableTableRecordsCount(): ?int;

    /**
     * Clears the selection server-side. Called by the filter and search hooks,
     * and by a bulk action that deselects after completion.
     */
    public function deselectAllTableRecords(): void;

    /**
     * Whether the column named is toggled off in the applied column state; a
     * name the state does not carry reads as hidden. Read by a toggleable
     * column through the table.
     */
    public function isTableColumnToggledHidden(string $name): bool;

    /**
     * The column manager's rows in the draft's order — every declared, unhidden
     * column with its label, whether the user may toggle it and whether it is
     * on. Read by the partial to render the manager.
     *
     * @return list<array{name: string, label: string|Markup, isToggleable: bool, isToggled: bool}>
     */
    public function getTableColumnManagerItems(): array;

    /**
     * One column's stored search term, raw — what its input renders as `value`.
     * Read by the search row.
     */
    public function getTableColumnSearch(string $column): string;

    /**
     * The per-column chips for the indicators row, one per individually
     * searchable column the developer has not hidden and that holds a term,
     * keyed by column name. A column the *user* toggled off still yields one.
     * Read by `Table::getColumnSearchIndicators()`.
     *
     * @return array<string, Indicator>
     */
    public function getTableColumnSearchIndicators(): array;
}
