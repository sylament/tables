<?php

declare(strict_types=1);

namespace Sylament\Tables\Concerns;

use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\Tools\Pagination\Paginator as DoctrinePaginator;
use LogicException;
use Sylament\Support\Pagination\Paginator;
use Sylament\Tables\Enums\PaginationMode;
use Sylament\Tables\Query\FetchJoinPageQuery;
use Sylament\Tables\Query\JoinCardinality;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\Metadata\UrlMapping;

trait CanPaginateRecords
{
    #[LiveProp(modifier: 'modifyTablePageProp')]
    public int $tablePage = 1;

    public function modifyTablePageProp(LiveProp $liveProp): LiveProp
    {
        $mapping = $this->getTableUrlMapping('page');

        return $mapping instanceof UrlMapping ? $liveProp->withUrl($mapping) : $liveProp;
    }

    /**
     * Stored as the raw client string; `getTableRecordsPerPage()` is the only
     * reader and validates on every call, so a forged value can never reach a
     * `records()` closure.
     */
    #[LiveProp(writable: true, dehydrateWith: 'dehydrateTableRecordsPerPage', onUpdated: 'updatedTableRecordsPerPage')]
    public ?string $tableRecordsPerPage = null;

    /**
     * The dehydration pass reads this prop through `getTableRecordsPerPage()`,
     * so `$value` is the resolved option; the wire carries it as the string the
     * `<select>` bound to the prop holds.
     */
    public function dehydrateTableRecordsPerPage(int|string|null $value): ?string
    {
        return $value === null ? null : (string) $value;
    }

    #[LiveAction]
    public function gotoTablePage(#[LiveArg] int $page): void
    {
        $this->tablePage = max(1, $page);
        $this->flushCachedTableRecords();
    }

    /**
     * Floored, because the prop is non-nullable and a `?page=` in the query
     * string coerces to `0` on the way in — seeding writes straight through the
     * property accessor, so `gotoTablePage()`'s clamp never sees it. Ceilinged
     * at the last page whose offset is still an integer, because that same
     * seeding accepts `PHP_INT_MAX` and the multiplication below it overflows
     * to a float that `setFirstResult()` refuses.
     */
    public function getTablePage(): int
    {
        $recordsPerPage = $this->getTableRecordsPerPage();
        $lastComputablePage = is_int($recordsPerPage) && $recordsPerPage > 0
            ? intdiv(PHP_INT_MAX, $recordsPerPage)
            : PHP_INT_MAX;

        return max(1, min($this->tablePage, $lastComputablePage));
    }

    public function updatedTableRecordsPerPage(mixed $previousValue = null): void
    {
        if ($previousValue === $this->tableRecordsPerPage) {
            return;
        }

        $this->tablePage = 1;
        $this->flushCachedTableRecords();
        $this->persistTableRecordsPerPageInSession();
    }

    /**
     * Null on an unpaginated table — a closure asking for `recordsPerPage`
     * there has nothing to slice by, matching Filament's unseeded state — and
     * null again on a paginated table with no page options, whose default is
     * null too. The prop is validated against those options, so a string other
     * than `'all'` only arrives from the table's `defaultPaginationPageOption()`.
     */
    public function getTableRecordsPerPage(): int|string|null
    {
        $table = $this->getTable();

        if (!$table->isPaginated()) {
            return null;
        }

        $value = $this->tableRecordsPerPage;
        $normalized = match (true) {
            $value === null => null,
            $value === 'all' => 'all',
            is_numeric($value) => (int) $value,
            default => null,
        };

        if ($normalized !== null && in_array($normalized, $table->getPaginationPageOptions(), true)) {
            return $normalized;
        }

        return $table->getDefaultPaginationPageOption();
    }

    protected function paginateTableQuery(QueryBuilder $query): Paginator
    {
        $mode = $this->getTable()->getPaginationMode();

        if ($mode === PaginationMode::Cursor) {
            throw new LogicException(sprintf('Table [%s] requests cursor pagination, which needs a keyset carrier the toolkit does not ship yet; use PaginationMode::Default or PaginationMode::Simple.', static::class));
        }

        $page = $this->getTablePage();
        $recordsPerPage = $this->getTableRecordsPerPage() ?? 'all';

        if ($recordsPerPage === 'all') {
            /** @var list<object|array<string, mixed>> $allRecords */
            $allRecords = $query->getQuery()->getResult();

            return new Paginator($this->keyTableRecords($allRecords), count($allRecords), $page, 'all');
        }

        $recordsPerPage = (int) $recordsPerPage;

        $records = $this->readTableRecordWindow(
            $query,
            ($page - 1) * $recordsPerPage,
            $mode === PaginationMode::Simple ? $recordsPerPage + 1 : $recordsPerPage,
        );

        if ($mode === PaginationMode::Simple) {
            return new Paginator(
                $this->keyTableRecords(array_slice($records, 0, $recordsPerPage)),
                totalRecordCount: null,
                page: $page,
                recordsPerPage: $recordsPerPage,
                hasMoreRecords: count($records) > $recordsPerPage,
            );
        }

        $this->assertTableQueryCarriesNoOutputWalker($query, $this->getCompositeTableRecordClass($query));

        return new Paginator($this->keyTableRecords($records), new DoctrinePaginator($query->getQuery(), JoinCardinality::joinsCollection($query))->count(), $page, $recordsPerPage);
    }

    /**
     * One window of the query's records, and the one place that decides how it
     * is read: a keyed root whose query joins a collection pages through the
     * two-step read, everything else through Doctrine's own paginator.
     *
     * `CanSummarizeRecords::readTableRecordAtOffset()` reads its window of one
     * from here, so the decision is written once.
     *
     * @return list<object|array<string, mixed>>
     */
    private function readTableRecordWindow(QueryBuilder $query, int $firstResult, int $maxResults): array
    {
        $keyedRecordClass = $this->getKeyedTableRecordClass($query);
        $joinsCollection = JoinCardinality::joinsCollection($query);

        if ($keyedRecordClass !== null && $joinsCollection) {
            $this->assertTableQueryCarriesNoOutputWalker($query, $keyedRecordClass);

            return FetchJoinPageQuery::read($query, $keyedRecordClass, $firstResult, $maxResults);
        }

        $query->setFirstResult($firstResult);
        $query->setMaxResults($maxResults);

        /** @var list<object|array<string, mixed>> $records */
        $records = iterator_to_array(new DoctrinePaginator($query->getQuery(), $joinsCollection)->getIterator(), preserve_keys: false);

        return $records;
    }

    /**
     * The record class when Doctrine's pagination walkers cannot resolve its
     * rows through one identifier column — two or more identifier fields, or a
     * single one that is an association — and null on every other table, which
     * pages through Doctrine's paginator.
     *
     * @return class-string|null
     */
    private function getKeyedTableRecordClass(QueryBuilder $query): ?string
    {
        $recordClass = $this->getTable()->getRecordClass();

        if ($recordClass === null) {
            return null;
        }

        $metadata = $query->getEntityManager()->getClassMetadata($recordClass);

        return $metadata->isIdentifierComposite || $metadata->containsForeignIdentifier ? $recordClass : null;
    }

    /**
     * The record class when Doctrine's `CountWalker` cannot total its rows — two
     * or more identifier fields — and null on every other table, a single
     * derived identity included: one association identifier is a path that
     * walker does resolve.
     *
     * A composite root is one of the keyed ones, which is why this reads through
     * `getKeyedTableRecordClass()` rather than beside it.
     *
     * @return class-string|null
     */
    private function getCompositeTableRecordClass(QueryBuilder $query): ?string
    {
        $recordClass = $this->getKeyedTableRecordClass($query);

        if ($recordClass === null) {
            return null;
        }

        return $query->getEntityManager()->getClassMetadata($recordClass)->isIdentifierComposite ? $recordClass : null;
    }

    /**
     * Refuses a read Doctrine cannot serve once the query carries an output
     * walker of its own. `$recordClass` is the class the calling site has
     * already found too widely keyed for what it is about to do, and null where
     * that site has nothing to refuse.
     *
     * Read off the built query rather than the builder, because
     * `Configuration::setDefaultQueryHints()` reaches only the former.
     *
     * @param class-string|null $recordClass
     *
     * @throws LogicException when the query sets its own output walker
     */
    private function assertTableQueryCarriesNoOutputWalker(QueryBuilder $query, ?string $recordClass): void
    {
        if ($recordClass === null) {
            return;
        }

        $walker = $query->getQuery()->getHint(Query::HINT_CUSTOM_OUTPUT_WALKER);

        if ($walker === false) {
            return;
        }

        throw new LogicException(sprintf('Cannot read table [%s]: the record class [%s] is keyed by more than one field or by an association, and the table query sets its own output walker [%s]. Doctrine pages and counts that shape through a single identifier column, and the identifier read needs its own walker. Drop the hint.', static::class, $recordClass, is_string($walker) ? $walker : get_debug_type($walker)));
    }
}
