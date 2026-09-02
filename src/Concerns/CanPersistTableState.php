<?php

declare(strict_types=1);

namespace Sylament\Tables\Concerns;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Contracts\Service\Attribute\Required;
use Symfony\UX\TwigComponent\Attribute\PostMount;

use function Sylament\Support\blank;

/**
 * The browsing state a table remembers between visits — sort, search, filters
 * and the per-page choice — read once per mount and written back wherever the
 * state changes.
 *
 * The read runs above the filters mount hook and below UX's own query-string
 * seeding, which is the whole precedence rule: the URL wins, the session fills
 * what the URL left blank, and the table's own defaults fill the rest. Per-page
 * is the exception Filament ships and this port keeps — it has no opt-out and
 * is read and written whether or not anything opted in.
 */
trait CanPersistTableState
{
    protected ?RequestStack $tableStateRequestStack = null;

    #[Required]
    public function setTableStateRequestStack(RequestStack $requestStack): void
    {
        $this->tableStateRequestStack = $requestStack;
    }

    #[PostMount(priority: 10)]
    public function mountTablePersistedState(): void
    {
        $table = $this->getTable();

        if ($table->persistsFiltersInSession() && $this->tableFilters === []) {
            $storedFilters = $this->readTableSessionState('filters');

            if (self::isPersistedTableFilterBag($storedFilters)) {
                $this->tableFilters = $storedFilters;
            }
        }

        if ($table->persistsSearchInSession() && blank($this->tableSearch)) {
            $storedSearch = $this->readTableSessionState('search');

            if (is_string($storedSearch)) {
                $this->tableSearch = $storedSearch;
            }
        }

        if ($table->persistsSortInSession() && blank($this->tableSort)) {
            $storedSort = $this->readTableSessionState('sort');

            if (is_string($storedSort)) {
                $this->tableSort = $storedSort;
            }
        }

        $this->persistTableSearchInSession();
        $this->persistTableSortInSession();

        if (!$table->isPaginated()) {
            return;
        }

        $this->tableRecordsPerPage ??= $this->readPersistedTableRecordsPerPage();

        $this->persistTableRecordsPerPageInSession();
    }

    /**
     * Filament's shape: the host class alone, so two instances of one page share
     * a table's remembered state and two different pages never do.
     */
    protected function getTableSessionKey(string $suffix): string
    {
        return sprintf('sylament.tables.%s_%s', md5(static::class), $suffix);
    }

    protected function persistTableStateToSession(string $suffix, mixed $value): void
    {
        $this->getTableStateSession()?->set($this->getTableSessionKey($suffix), $value);
    }

    protected function readTableSessionState(string $suffix): mixed
    {
        return $this->getTableStateSession()?->get($this->getTableSessionKey($suffix));
    }

    protected function forgetTableSessionState(string $suffix): void
    {
        $this->getTableStateSession()?->remove($this->getTableSessionKey($suffix));
    }

    protected function persistTableSortInSession(): void
    {
        if (!$this->getTable()->persistsSortInSession()) {
            return;
        }

        $this->persistTableStateToSession('sort', $this->tableSort);
    }

    protected function persistTableSearchInSession(): void
    {
        if (!$this->getTable()->persistsSearchInSession()) {
            return;
        }

        $this->persistTableStateToSession('search', $this->tableSearch);
    }

    protected function persistTableFiltersInSession(): void
    {
        if (!$this->getTable()->persistsFiltersInSession()) {
            return;
        }

        $this->persistTableStateToSession('filters', $this->tableFilters);
    }

    protected function persistTableRecordsPerPageInSession(): void
    {
        if ($this->tableRecordsPerPage === null) {
            return;
        }

        $this->persistTableStateToSession('per_page', $this->tableRecordsPerPage);
    }

    /**
     * The request the page was loaded with, which is where a query string lives
     * — a live round trip's own request carries the live route's parameters
     * instead, and never the page's.
     */
    protected function getTableStateMainRequest(): ?Request
    {
        return $this->tableStateRequestStack?->getMainRequest();
    }

    /**
     * A filter bag arriving from outside the component — the session, whose
     * contents an earlier request wrote against a table that may have looked
     * nothing like this one, or the query string — is as untrusted as any other
     * client value, so one that is not filter-name-keyed is left where it is.
     *
     * @phpstan-assert-if-true array<string, mixed> $value
     */
    private static function isPersistedTableFilterBag(mixed $value): bool
    {
        return is_array($value) && array_all(array_keys($value), static fn (int|string $key): bool => is_string($key));
    }

    /**
     * A remembered per-page choice, validated against the options the table
     * offers now; one that no longer exists is forgotten rather than left to
     * fail validation on every later visit.
     */
    private function readPersistedTableRecordsPerPage(): ?string
    {
        $stored = $this->readTableSessionState('per_page');

        if (!is_string($stored)) {
            return null;
        }

        $normalized = match (true) {
            $stored === 'all' => 'all',
            is_numeric($stored) => (int) $stored,
            default => null,
        };

        if ($normalized !== null && in_array($normalized, $this->getTable()->getPaginationPageOptions(), true)) {
            return $stored;
        }

        $this->forgetTableSessionState('per_page');

        return null;
    }

    /**
     * Null wherever there is no session to reach — a console command, a
     * sub-request, a test mounting the component by hand — which turns every
     * read and write below into a no-op rather than an exception.
     */
    private function getTableStateSession(): ?SessionInterface
    {
        $request = $this->tableStateRequestStack?->getCurrentRequest();

        if ($request === null || !$request->hasSession()) {
            return null;
        }

        return $request->getSession();
    }
}
