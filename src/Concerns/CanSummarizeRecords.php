<?php

declare(strict_types=1);

namespace Sylament\Tables\Concerns;

use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\Tools\Pagination\Paginator as DoctrinePaginator;
use Sylament\Support\Pagination\Paginator;
use Sylament\Tables\Columns\Summarizers\Contracts\HasSelectAlias;
use Sylament\Tables\Grouping\Group;
use Sylament\Tables\Query\AggregateDeclaration;
use Sylament\Tables\Query\JoinCardinality;
use Sylament\Tables\Query\SummaryQuery;

trait CanSummarizeRecords
{
    /**
     * The dataset the all-table row summarizes: filters, the search and the per-column
     * searches applied, nothing sorted and nothing paged. A fresh builder every call,
     * so a caller that constrains one has changed nothing for the next.
     */
    public function getAllTableSummaryQuery(): ?QueryBuilder
    {
        return $this->getFilteredTableQuery();
    }

    /**
     * The same dataset narrowed to the keys of the page on screen — never by a limit,
     * which would bound the aggregate's own result row and read as the all-table
     * figure. Null where there is no page to narrow to: off the query lane, on an
     * unpaginated table, and on an empty page, whose empty key list would bind an
     * `IN ()` most platforms refuse.
     */
    public function getPageTableSummaryQuery(): ?QueryBuilder
    {
        $query = $this->getFilteredTableQuery();
        $paginator = $this->getTablePaginator();

        if (!$query instanceof QueryBuilder || !$paginator instanceof Paginator) {
            return null;
        }

        $keys = array_keys($paginator->getRecords());
        $table = $this->getTable();
        $recordClass = $table->getRecordClass();
        $entityManager = $table->getQueryEntityManager();
        $rootAlias = $query->getRootAliases()[0] ?? null;

        if ($keys === [] || $recordClass === null || $entityManager === null || $rootAlias === null) {
            return null;
        }

        return $query
            ->andWhere(sprintf('%s.%s IN (:sylament_summary_keys)', $rootAlias, $entityManager->getClassMetadata($recordClass)->getSingleIdentifierFieldName()))
            ->setParameter('sylament_summary_keys', array_map(strval(...), $keys));
    }

    /**
     * The all-table dataset narrowed to the one group a record belongs to, which is
     * how a per-group summary row reads a figure without a `GROUP BY`: one scoped
     * statement per group on the page, each over a builder of its own, so the group
     * that asks first does not narrow the row that asks next. The scope is the
     * group's own, so a `scopeQueryUsing()` closure answers here exactly as it does
     * for the header the figure sits under.
     *
     * Null where there is no group to narrow to: off the query lane, and on a table
     * that groups nothing.
     *
     * @param object|array<string, mixed> $record
     */
    public function getGroupedTableSummaryQuery(object|array $record): ?QueryBuilder
    {
        $query = $this->getAllTableSummaryQuery();
        $group = $this->getTableGrouping();

        if (!$query instanceof QueryBuilder || !$group instanceof Group) {
            return null;
        }

        return $group->scopeQuery($query, $record);
    }

    /**
     * Whether the group the page's last record closed is finished, or continues onto
     * the next page — where its summary row would put a partial figure under a
     * heading naming the whole group.
     *
     * Four shapes answer without asking the database, in Filament's own order: there
     * is no record to summarize; no next page exists to continue anything, which
     * covers an unpaginated table, the last page and a page holding every record;
     * nothing is grouped; or there is no query to look ahead in. Only what is left
     * pays for the look-ahead, and it pays once.
     *
     * A record rather than a scalar key, because the key may be a
     * `getKeyFromRecordUsing()` closure's answer and the closure needs the record.
     *
     * Off the contract on purpose: the boundary map's own gate is the only caller,
     * and it reaches this through the host it already holds.
     *
     * @param object|array<string, mixed>|null $lastRecord
     */
    public function shouldRenderTrailingGroupedTableSummary(object|array|null $lastRecord): bool
    {
        if ($lastRecord === null) {
            return false;
        }

        $paginator = $this->getTablePaginator();

        if (!$paginator instanceof Paginator || !$paginator->hasMorePages()) {
            return true;
        }

        $group = $this->getTableGrouping();

        if (!$group instanceof Group) {
            return true;
        }

        $query = $this->getFilteredSortedTableQuery();
        $recordsPerPage = $paginator->getRecordsPerPage();

        if (!$query instanceof QueryBuilder || $recordsPerPage === 'all') {
            return true;
        }

        $nextPageFirstRecord = $this->readTableRecordAtOffset($query, $paginator->getPage() * $recordsPerPage);

        if ($nextPageFirstRecord === null) {
            return true;
        }

        return $group->getStringKey($nextPageFirstRecord) !== $group->getStringKey($lastRecord);
    }

    /**
     * One statement carrying every batchable summarizer of every visible column,
     * hydrated as one flat alias ⇒ value row for the whole summary row to read.
     *
     * The alias is pushed onto each summarizer *before* its statements are read, so
     * the name the SELECT carries is the one the summarizer looks its figure up under;
     * it is deterministic and counter-suffixed, because a DQL result variable cannot
     * start with a digit and a random one cannot be asserted.
     *
     * The ordinal counts the **declared** summarizers of every visible column rather
     * than the ones this query batches, so one summarizer holds one alias for the whole
     * render. A page renders several batches — the page one, the all-table one and one
     * per group — and `hidden(fn ($query) => …)` is a supported, query-dependent
     * predicate, so an ordinal counted over the visible set would re-mint a later
     * batch's aliases onto the names an earlier batch's row is still about to read,
     * and one summarizer would publish another's figure.
     *
     * Three summarizers never reach the statement: one that scopes the query itself,
     * because the batch's figure is not the scoped one; one that emits no statement at
     * all, a range or a list; and every summarizer of an aggregate column, whose value
     * lives in a side store — that one is refused where it is read, not here. Only the
     * first two consume an ordinal, both being properties of the summarizer rather than
     * of the query. The column's expression is resolved only once one of its
     * summarizers has passed all three *and* is visible here, so a path the qualifier
     * refuses is refused for the summarizers that would have aggregated it and for
     * nobody else.
     *
     * @return array<string, mixed> alias ⇒ raw driver value, empty when nothing batches
     */
    public function getTableSummarySelectedState(?QueryBuilder $query): array
    {
        if (!$query instanceof QueryBuilder) {
            return [];
        }

        $selects = [];
        $ordinal = 0;

        foreach ($this->getTable()->getVisibleColumns() as $column) {
            $visibleSummarizers = $column->getSummarizers($query);

            if ($column->getCellAggregateDeclaration() instanceof AggregateDeclaration) {
                continue;
            }

            $attribute = null;

            foreach ($column->getSummarizers() as $key => $summarizer) {
                if (!$summarizer instanceof HasSelectAlias || $summarizer->hasQueryModification()) {
                    continue;
                }

                $alias = 'sy_summary_' . $ordinal;
                $ordinal++;

                if (!array_key_exists((string) $key, $visibleSummarizers)) {
                    continue;
                }

                $attribute ??= SummaryQuery::qualifyAttribute($query, $column->getName());

                foreach ($summarizer->selectAlias($alias)->getSelectStatements($attribute) as $statementAlias => $statement) {
                    $selects[] = "{$statement} AS {$statementAlias}";
                }
            }
        }

        return $selects === [] ? [] : SummaryQuery::readRow($query, $selects);
    }

    /**
     * The one record sitting at `$offset` in the sorted query, or null where the
     * query ends before it — the paginator counted its rows before this read runs,
     * so a page that claims a successor can still be followed by nothing.
     *
     * A window of one is a window over *rows*, and a to-many fetch join makes rows
     * and records different things, so that shape is read through Doctrine's own
     * paginator instead — the guard `paginateTableQuery()` applies to a whole page,
     * over a page of one.
     *
     * @return object|array<string, mixed>|null
     */
    private function readTableRecordAtOffset(QueryBuilder $query, int $offset): object|array|null
    {
        $probe = (clone $query)->setFirstResult($offset)->setMaxResults(1);

        if (JoinCardinality::joinsCollection($probe)) {
            /** @var list<object|array<string, mixed>> $records */
            $records = iterator_to_array(new DoctrinePaginator($probe->getQuery(), fetchJoinCollection: true)->getIterator(), preserve_keys: false);

            return $records[0] ?? null;
        }

        /** @var list<object|array<string, mixed>> $records */
        $records = $probe->getQuery()->getResult();

        return $records[0] ?? null;
    }
}
