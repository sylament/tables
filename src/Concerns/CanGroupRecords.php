<?php

declare(strict_types=1);

namespace Sylament\Tables\Concerns;

use Doctrine\ORM\QueryBuilder;
use Sylament\Support\Pagination\Paginator;
use Sylament\Tables\Grouping\Group;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\Metadata\UrlMapping;
use Symfony\UX\TwigComponent\Attribute\PostMount;
use Twig\Markup;

use function Sylament\Support\blank;

trait CanGroupRecords
{
    /**
     * `<id>`, or `<id>:desc`; a bare id means ascending, and blank means the
     * user chose no grouping — which is not the same as the table having none,
     * since a non-selectable default group resolves without ever being written
     * here. Non-writable, so the client reaches it only through `groupTable()`;
     * the query string reaches it at mount.
     */
    #[LiveProp(dehydrateWith: 'dehydrateTableGrouping', modifier: 'modifyTableGroupingProp')]
    public ?string $tableGrouping = null;

    /**
     * Invalidated with the records it describes: `flushCachedTableRecords()` nulls
     * it beside `cachedTableRecords`, so nothing that reshapes the page — a new
     * grouping, sort, filter, search or page — can leave a stale map behind.
     *
     * @var array<array-key, array{key: ?string, title: string|Markup|null, description: string|Markup|null, isFirstOfGroup: bool, isLastOfGroup: bool, indexInGroup: int}>|null
     */
    private ?array $cachedTableRecordGroupBoundaries = null;

    /**
     * The wire value is the property, not the `Group` the dehydration pass reads
     * through the same-named getter — Symfony's property accessor prefers an
     * accessor over the property, and a `Group` carries no property type to
     * hydrate back into, so without this the first grouped render throws.
     *
     * Blank is published as nothing at all: a routed page reached with a bare
     * `?grouping=` seeds `''`, which the mount leaves standing, and publishing
     * that would write `grouping=` back onto every rebuilt URL.
     */
    public function dehydrateTableGrouping(mixed $value): ?string
    {
        return blank($this->tableGrouping) ? null : $this->tableGrouping;
    }

    public function modifyTableGroupingProp(LiveProp $liveProp): LiveProp
    {
        $mapping = $this->getTableUrlMapping('grouping');

        return $mapping instanceof UrlMapping ? $liveProp->withUrl($mapping) : $liveProp;
    }

    /**
     * The mount edge of the same check `groupTable()` runs, over the one writer
     * that bypasses the action: a query string naming a group the table does not
     * offer, or a direction that is not one. Nothing is persisted — Filament
     * keeps no grouping in the session and neither does the port — so the
     * cleared value is not written anywhere but back onto the property.
     *
     * The seed follows the heal, and only into a blank property: a selectable
     * default has to reach the property for the selector to show it and offer
     * "no grouping" beside it. Filament seeds it unconditionally, discarding a
     * bookmarked `?grouping=`; every neighbouring seed in that method guards on
     * blank and this one does not.
     */
    #[PostMount(priority: 2)]
    public function mountTableGrouping(): void
    {
        $table = $this->getTable();

        if (!blank($this->tableGrouping)) {
            $column = $this->getTableGroupingColumn();

            if ($column !== null
                && $table->getGroup($column) instanceof Group
                && $this->getTableGroupingDirection() !== null) {
                return;
            }

            $this->tableGrouping = null;
        }

        if ($table->isDefaultGroupSelectable()) {
            $this->tableGrouping = $table->getDefaultGroup()?->getId();
        }
    }

    /**
     * Both writers of the property validate, so a resolved grouping always names
     * a group the table offered when it was last written: a request naming
     * anything else is ignored here, and a seeded value naming anything else is
     * cleared at mount.
     */
    #[LiveAction]
    public function groupTable(#[LiveArg] ?string $group = null, #[LiveArg] ?string $direction = null): void
    {
        if (!in_array($direction, ['asc', 'desc', null], true)) {
            return;
        }

        if (blank($group)) {
            $this->tableGrouping = null;
        } else {
            if (!$this->getTable()->getGroup($group) instanceof Group) {
                return;
            }

            $this->tableGrouping = $direction === 'desc' ? "{$group}:desc" : $group;
        }

        $this->tablePage = 1;
        $this->flushCachedTableRecords();
    }

    /**
     * The group in force. Reordering wins over everything, since a drag orders
     * the rows itself; then the property, re-checked against the offered groups
     * on every read; then null where the default is itself selectable, because
     * the mount seeded it into the property and the user is free to have chosen
     * nothing instead; and the default group otherwise.
     */
    public function getTableGrouping(): ?Group
    {
        if ($this->isTableReordering()) {
            return null;
        }

        $table = $this->getTable();
        $column = $this->getTableGroupingColumn();

        if ($column !== null && $table->getGroup($column) instanceof Group) {
            return $table->getGroup($column);
        }

        if ($table->isDefaultGroupSelectable()) {
            return null;
        }

        return $table->getDefaultGroup();
    }

    public function getTableGroupingColumn(): ?string
    {
        if (blank($this->tableGrouping)) {
            return null;
        }

        return explode(':', (string) $this->tableGrouping, 2)[0];
    }

    /**
     * Null is not "ungrouped": a group resolved from the default carries no
     * direction here and the query lane falls back to ascending.
     */
    public function getTableGroupingDirection(): ?string
    {
        if (blank($this->tableGrouping)) {
            return null;
        }

        $parts = explode(':', (string) $this->tableGrouping, 2);

        if (!isset($parts[1])) {
            return 'asc';
        }

        return match ($parts[1]) {
            'asc', 'desc' => $parts[1],
            default => null,
        };
    }

    /**
     * Where each rendered record sits in its group, keyed by record key and
     * empty when the table is not grouped — so the partial reads one entry per
     * row rather than tracking a previous record across its loop. The break is
     * on the *title*, string-cast, which is Filament's own test: two keys that
     * read the same are one heading.
     *
     * The page's final record has no next row to break against, so where the table
     * summarizes, its answer is the trailing probe's — one look past the page
     * break — and where it does not, it is simply true and nothing reads it.
     *
     * The key type is `getTableRecords()`'s and for its reason: PHP coerces a
     * numeric-string record key to an int.
     *
     * Memoised, because on a summarized table the map costs the trailing probe's
     * statement and it has two readers by design — the group header rows and the
     * trailing summary row — either of which a template may read per record.
     *
     * @return array<array-key, array{key: ?string, title: string|Markup|null, description: string|Markup|null, isFirstOfGroup: bool, isLastOfGroup: bool, indexInGroup: int}>
     */
    public function getTableRecordGroupBoundaries(): array
    {
        return $this->cachedTableRecordGroupBoundaries ??= $this->resolveTableRecordGroupBoundaries();
    }

    /**
     * @return array<array-key, array{key: ?string, title: string|Markup|null, description: string|Markup|null, isFirstOfGroup: bool, isLastOfGroup: bool, indexInGroup: int}>
     */
    private function resolveTableRecordGroupBoundaries(): array
    {
        $group = $this->getTableGrouping();

        if (!$group instanceof Group) {
            return [];
        }

        $records = $this->getTableRecords();

        if ($records instanceof Paginator) {
            $records = $records->getRecords();
        }

        /** @var list<array{record: object|array<string, mixed>, recordKey: string, key: ?string, title: string|Markup|null, description: string|Markup|null}> $rows */
        $rows = [];

        foreach ($records as $recordKey => $record) {
            $key = $group->getStringKey($record);
            $title = $group->getTitle($record, $key);

            $rows[] = [
                'record' => $record,
                'recordKey' => (string) $recordKey,
                'key' => $key,
                'title' => $title,
                'description' => $group->getDescription($record, $title),
            ];
        }

        $boundaries = [];
        $indexInGroup = 0;
        $count = count($rows);

        foreach ($rows as $position => $row) {
            $title = (string) $row['title'];
            $isFirstOfGroup = $position === 0 || (string) $rows[$position - 1]['title'] !== $title;
            $indexInGroup = $isFirstOfGroup ? 0 : $indexInGroup + 1;

            $boundaries[$row['recordKey']] = [
                'key' => $row['key'],
                'title' => $row['title'],
                'description' => $row['description'],
                'isFirstOfGroup' => $isFirstOfGroup,
                'isLastOfGroup' => $position + 1 === $count
                    ? $this->closesTrailingTableRecordGroup($row['record'])
                    : (string) $rows[$position + 1]['title'] !== $title,
                'indexInGroup' => $indexInGroup,
            ];
        }

        return $boundaries;
    }

    /**
     * Whether the page's final record closes its group. Nothing but the trailing
     * summary row hangs on the answer, so an unsummarized table gets the cheap one
     * and spends no statement asking; a summarized one is followed past the page
     * break, where a group that continues would otherwise be summarized twice —
     * once here over part of itself, once on the next page over the rest.
     *
     * @param object|array<string, mixed> $record
     */
    private function closesTrailingTableRecordGroup(object|array $record): bool
    {
        if (!$this->getTable()->hasSummary($this->getAllTableSummaryQuery())) {
            return true;
        }

        return $this->shouldRenderTrailingGroupedTableSummary($record);
    }

    /**
     * The grouping's own ordering, applied before the sorts so that it is the
     * outermost one and everything the table adds after it breaks ties inside a
     * group rather than across them.
     */
    protected function applyGroupingToTableQuery(QueryBuilder $query): QueryBuilder
    {
        $group = $this->getTableGrouping();

        if (!$group instanceof Group) {
            return $query;
        }

        $group->applyEagerLoading($query);

        return $group->orderQuery($query, $this->getTableGroupingDirection() ?? 'asc');
    }
}
