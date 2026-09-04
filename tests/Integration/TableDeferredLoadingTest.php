<?php

declare(strict_types=1);

namespace Sylament\Tables\Tests\Integration;

use Closure;
use Sylament\Actions\Action;
use Sylament\Actions\BulkAction;
use Sylament\Tables\Columns\Summarizers\Sum;
use Sylament\Tables\Columns\TextColumn;
use Sylament\Tables\Table;
use Sylament\Tables\Tests\Fixtures\Concerns\TableHost;
use Sylament\Tables\Tests\Fixtures\Doctrine\Entity\Song;
use Sylament\Tables\Tests\Fixtures\Doctrine\EntityManagerFactory;
use Sylament\Tables\Tests\Fixtures\Doctrine\RecordingMiddleware;
use Symfony\Component\DomCrawler\Crawler;

use function Sylament\Tables\Tests\browseTo;
use function Sylament\Tables\Tests\publishedProps;
use function Sylament\Tables\Tests\renderToHtml;

/*
 * The deferred window and the poll interval as the partial renders them. The
 * records-touching reads are counted rather than described: the data source
 * increments a counter, so deleting any one of the four `records is not null`
 * gates — the preamble read, the paginator, the visible-action map or the
 * selectable ceiling — turns the deferred first render into a read and fails
 * the zero-query case here.
 */

/**
 * A three-row selectable table carrying record actions, over a data source that
 * counts every time it is asked.
 *
 * @param int $reads incremented once per records read
 */
function deferrableTableHost(int &$reads, ?Closure $configure = null): TableHost
{
    $reads = 0;

    return new TableHost(function (Table $table) use (&$reads, $configure): Table {
        $table
            ->records(function () use (&$reads): array {
                $reads++;

                return [
                    ['__key' => 'a', 'title' => 'Airbag'],
                    ['__key' => 'b', 'title' => 'Bones'],
                    ['__key' => 'c', 'title' => 'Creep'],
                ];
            })
            ->columns([TextColumn::make('title')])
            ->recordActions([Action::make('edit')])
            ->toolbarActions([BulkAction::make('archive')]);

        return ($configure ?? static fn (Table $table): Table => $table)($table);
    });
}

/**
 * The same shape on the query lane, over a recording connection: a summary
 * figure is an aggregate, so a read that escapes the loaded branch is a
 * statement here rather than a bump in a counter.
 */
function deferrableSummarizedTableHost(RecordingMiddleware $recorder, ?Closure $configure = null): TableHost
{
    $entityManager = EntityManagerFactory::create($recorder);

    EntityManagerFactory::seed(
        $entityManager,
        new Song(id: 1, title: 'Airbag', plays: 10, mood: 'calm', position: 1),
        new Song(id: 2, title: 'Airport', plays: 20, mood: 'calm', position: 2),
        new Song(id: 3, title: 'Jóga', plays: 30, mood: 'bright', position: 3),
    );

    $recorder->reset();

    return new TableHost(fn (Table $table): Table => ($configure ?? static fn (Table $table): Table => $table)(
        $table
            ->query($entityManager->createQueryBuilder()->select('song')->from(Song::class, 'song')->orderBy('song.id', 'ASC'))
            ->columns([TextColumn::make('title'), TextColumn::make('plays')->summarize(Sum::make())]),
    ));
}

it('renders the loading block and reads no records while the window is open', function (): void {
    $reads = 0;
    $host = deferrableTableHost($reads, fn (Table $table): Table => $table->deferLoading());

    $crawler = new Crawler(renderToHtml($host->getTable()));
    $loading = $crawler->filter('.sy-ta-table-loading-ctn');

    expect($reads)->toBe(0)
        ->and($loading->count())->toBe(1)
        ->and($loading->attr('role'))->toBe('status')
        ->and($loading->attr('aria-busy'))->toBe('true')
        ->and($loading->filter('svg.sy-loading-indicator.sy-size-2xl')->count())->toBe(1)
        ->and($loading->filter('span.sy-ta-sr-only')->text())->toBe('Loading')
        ->and($crawler->filter('table.sy-ta-table')->count())->toBe(0)
        ->and($crawler->filter('.sy-ta-empty-state')->count())->toBe(0)
        ->and($crawler->filter('.sy-ta-selection-indicator')->count())->toBe(0)
        ->and($crawler->filter('.sy-pagination')->count())->toBe(0);
});

it('renders the rows once the loading action has run', function (): void {
    $reads = 0;
    $host = deferrableTableHost($reads, fn (Table $table): Table => $table->deferLoading());

    renderToHtml($host->getTable());
    $host->loadTable();

    $crawler = new Crawler(renderToHtml($host->getTable()));

    expect($reads)->toBeGreaterThan(0)
        ->and($crawler->filter('.sy-ta-table-loading-ctn')->count())->toBe(0)
        ->and($crawler->filter('tbody > tr.sy-ta-row')->count())->toBe(3)
        ->and($crawler->filter('.sy-ta-selection-indicator')->count())->toBe(1);
});

it('reads the records straight away when nothing is deferred', function (): void {
    $reads = 0;
    $host = deferrableTableHost($reads);

    $crawler = new Crawler(renderToHtml($host->getTable()));

    expect($reads)->toBe(1)
        ->and($host->isTableLoaded)->toBeFalse()
        ->and($crawler->filter('.sy-ta-table-loading-ctn')->count())->toBe(0)
        ->and($crawler->filter('tbody > tr.sy-ta-row')->count())->toBe(3);
});

it('renders the empty state rather than the loading block for a loaded empty table', function (): void {
    $host = new TableHost(fn (Table $table): Table => $table
        ->records(fn (): array => [])
        ->columns([TextColumn::make('title')]));

    $crawler = new Crawler(renderToHtml($host->getTable()));

    expect($crawler->filter('.sy-ta-table-loading-ctn')->count())->toBe(0)
        ->and($crawler->filter('.sy-ta-empty-state')->count())->toBe(1);
});

it('mounts the controller for a deferred table and tells it the window is open', function (): void {
    $reads = 0;
    $host = deferrableTableHost($reads, fn (Table $table): Table => $table->deferLoading()->selectable(false));

    $root = new Crawler(renderToHtml($host->getTable()))->filter('div.sy.sy-ta');

    expect($root->attr('data-controller'))->toBe('sylament--tables--table')
        ->and($root->attr('data-sylament--tables--table-deferred-value'))->toBe('true')
        ->and($root->attr('data-sylament--tables--table-all-selectable-count-value'))->toBeNull()
        ->and($root->attr('data-sylament--tables--table-poll-interval-value'))->toBeNull();
});

it('mounts the controller for a polling table alone and hands it milliseconds', function (): void {
    $reads = 0;
    $host = deferrableTableHost($reads, fn (Table $table): Table => $table->poll('30s')->selectable(false));

    $root = new Crawler(renderToHtml($host->getTable()))->filter('div.sy.sy-ta');

    expect($root->attr('data-controller'))->toBe('sylament--tables--table')
        ->and($root->attr('data-sylament--tables--table-poll-interval-value'))->toBe('30000')
        ->and($root->attr('data-sylament--tables--table-deferred-value'))->toBe('false');
});

it('withholds the poll interval while the table is being reordered', function (): void {
    $reads = 0;
    $host = deferrableTableHost($reads, fn (Table $table): Table => $table
        ->poll('10s')
        ->selectable(false)
        ->reorderable('position'));

    $host->isTableReordering = true;

    $root = new Crawler(renderToHtml($host->getTable()))->filter('div.sy.sy-ta');

    expect($root->attr('data-sylament--tables--table-poll-interval-value'))->toBeNull()
        ->and($root->attr('data-controller'))->toBe('sylament--tables--table');
});

it('publishes the loaded flag on the wire, which is where the controller reads it', function (): void {
    [, $crawler] = browseTo();

    expect(publishedProps($crawler, '#bound-table'))->toHaveKey('isTableLoaded', true);
});

it('mounts no controller for a table that neither selects, polls, defers nor reorders', function (): void {
    $reads = 0;
    $host = deferrableTableHost($reads, fn (Table $table): Table => $table->selectable(false));

    expect(new Crawler(renderToHtml($host->getTable()))->filter('div.sy.sy-ta')->attr('data-controller'))->toBeNull();
});

it('reads no records for a grouped table while the window is open', function (): void {
    $reads = 0;
    $host = deferrableTableHost($reads, fn (Table $table): Table => $table
        ->deferLoading()
        ->defaultGroup('title'));

    $crawler = new Crawler(renderToHtml($host->getTable()));

    expect($reads)->toBe(0)
        ->and($crawler->filter('tr.sy-ta-group-header-row')->count())->toBe(0)
        ->and($crawler->filter('.sy-ta-table-loading-ctn')->count())->toBe(1);
});

it('opens the group header rows for the same table once the window has closed', function (): void {
    $reads = 0;
    $host = deferrableTableHost($reads, fn (Table $table): Table => $table->defaultGroup('title'));

    $crawler = new Crawler(renderToHtml($host->getTable()));

    expect($reads)->toBeGreaterThan(0)
        ->and($crawler->filter('tr.sy-ta-group-header-row')->count())->toBe(3);
});

it('issues no aggregate for a grouped, summarized table while the window is open', function (): void {
    $recorder = new RecordingMiddleware();
    $host = deferrableSummarizedTableHost($recorder, fn (Table $table): Table => $table
        ->deferLoading()
        ->defaultGroup('mood'));

    $deferred = new Crawler(renderToHtml($host->getTable()));
    $deferredStatements = $recorder->statements();

    // Every summary read sits inside the loaded branch beside the boundary map,
    // so a deferred table asks the database for nothing at all: the figures are
    // aggregates, and one computed for a band that will not render is a
    // statement issued for nothing. The same table run to the far side of the
    // window is what shows the assertion has teeth.
    $host->loadTable();

    $loaded = new Crawler(renderToHtml($host->getTable()));
    $aggregates = array_filter($recorder->statements(), static fn (string $sql): bool => str_contains($sql, 'SUM('));

    expect($deferredStatements)->toBeEmpty()
        ->and($deferred->filter('.sy-ta-table-loading-ctn')->count())->toBe(1)
        ->and($deferred->filter('tr.sy-ta-summary-row')->count())->toBe(0)
        ->and($deferred->filter('tr.sy-ta-summary-header-row')->count())->toBe(0)
        ->and($deferred->filter('tr.sy-ta-group-header-row')->count())->toBe(0)
        ->and($loaded->filter('tr.sy-ta-summary-row')->count())->toBeGreaterThan(0)
        ->and($loaded->filter('tr.sy-ta-group-header-row')->count())->toBe(2)
        ->and($aggregates)->not->toBeEmpty();
});
