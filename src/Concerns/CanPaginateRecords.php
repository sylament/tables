<?php

declare(strict_types=1);

namespace Sylament\Tables\Concerns;

use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\Tools\Pagination\Paginator as DoctrinePaginator;
use LogicException;
use Sylament\Support\Pagination\Paginator;
use Sylament\Tables\Enums\PaginationMode;
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
        $fetchJoinCollection = JoinCardinality::joinsCollection($query);

        $query->setFirstResult(($page - 1) * $recordsPerPage);
        $query->setMaxResults($mode === PaginationMode::Simple ? $recordsPerPage + 1 : $recordsPerPage);

        $doctrinePaginator = new DoctrinePaginator($query->getQuery(), $fetchJoinCollection);

        /** @var list<object|array<string, mixed>> $records */
        $records = iterator_to_array($doctrinePaginator->getIterator(), preserve_keys: false);

        if ($mode === PaginationMode::Simple) {
            return new Paginator(
                $this->keyTableRecords(array_slice($records, 0, $recordsPerPage)),
                totalRecordCount: null,
                page: $page,
                recordsPerPage: $recordsPerPage,
                hasMoreRecords: count($records) > $recordsPerPage,
            );
        }

        return new Paginator($this->keyTableRecords($records), $doctrinePaginator->count(), $page, $recordsPerPage);
    }
}
