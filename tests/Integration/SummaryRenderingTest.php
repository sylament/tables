<?php

declare(strict_types=1);

namespace Sylament\Tables\Tests\Integration;

use Closure;
use Doctrine\ORM\QueryBuilder;
use Sylament\Actions\Action;
use Sylament\Actions\BulkAction;
use Sylament\Tables\Columns\Column;
use Sylament\Tables\Columns\Summarizers\Average;
use Sylament\Tables\Columns\Summarizers\Count;
use Sylament\Tables\Columns\Summarizers\Range;
use Sylament\Tables\Columns\Summarizers\Sum;
use Sylament\Tables\Columns\Summarizers\Values;
use Sylament\Tables\Columns\TextColumn;
use Sylament\Tables\Contracts\InteractsWithTable;
use Sylament\Tables\Enums\RecordActionsPosition;
use Sylament\Tables\Enums\RecordCheckboxPosition;
use Sylament\Tables\Table;
use Sylament\Tables\Tests\Fixtures\Concerns\TableHost;
use Sylament\Tables\Tests\Fixtures\Doctrine\Entity\Song;
use Sylament\Tables\Tests\Fixtures\Doctrine\EntityManagerFactory;
use Sylament\Tables\Tests\Fixtures\Doctrine\RecordingMiddleware;
use Sylament\Tables\Tests\Fixtures\Twig\Components\SummarySongsTable;
use Symfony\Component\DomCrawler\Crawler;

use function Sylament\Tables\Tests\renderToHtml;

/*
 * The summary band as the partial renders it: the header row, the page row and
 * the all-table row inside `<tbody>`, the per-group row at each boundary, the
 * heading cell's colspan arithmetic, the two a11y rules, the placeholder cells
 * that keep every summary row as wide as the header, and the three summarizer
 * markups the cells render.
 *
 * A record row is counted as `tbody tr[id*=".table.records."]`, never as
 * `tbody .sy-ta-row` — every summary row carries that class too, as vendor's
 * does.
 */

/**
 * A five-song summarized table over a fresh in-memory rig: plays 10…50, three
 * moods, five titles. The columns are the caller's, because every case here is
 * about which of them summarize and where their cells land.
 *
 * @param list<Column> $columns
 */
function summarizedTableHost(array $columns, ?Closure $configure = null, ?string $grouping = null, ?RecordingMiddleware $recorder = null): TableHost
{
    $entityManager = EntityManagerFactory::create($recorder);

    EntityManagerFactory::seed(
        $entityManager,
        new Song(id: 1, title: 'Airbag', plays: 10, mood: 'calm', position: 1),
        new Song(id: 2, title: 'Airport', plays: 20, mood: 'calm', position: 2),
        new Song(id: 3, title: 'Jóga', plays: 30, mood: 'bright', position: 3),
        new Song(id: 4, title: 'Hyperballad', plays: 40, mood: 'bright', position: 4),
        new Song(id: 5, title: 'Bachelorette', plays: 50, mood: 'dark', position: 5),
    );

    $host = new TableHost(fn (Table $table): Table => ($configure ?? static fn (Table $table): Table => $table)(
        $table
            ->query($entityManager->createQueryBuilder()->select('song')->from(Song::class, 'song')->orderBy('song.id', 'ASC'))
            ->columns($columns),
    ));

    $host->tableGrouping = $grouping;

    return $host;
}

/**
 * The same table, rendered and crawled.
 *
 * @param list<Column> $columns
 */
function summarizedTableCrawler(array $columns, ?Closure $configure = null, ?string $grouping = null): Crawler
{
    return new Crawler(renderToHtml(summarizedTableHost($columns, $configure, $grouping)->getTable()));
}

/**
 * One summary row of a host's own columns, rendered on its own — the only way
 * to reach `extraHeadingColumn`, which the table layout never sets because it
 * belongs to the content one (M4 · 7).
 *
 * @param array<string, mixed> $props
 */
function summaryRowCrawler(InteractsWithTable $host, array $props = []): Crawler
{
    $twig = test()->getContainer()->get('twig');

    return new Crawler($twig->createTemplate(
        '<table><tbody><twig:SylamentTables:SummaryRow :columns="columns" heading="Summary" :query="query" '
        . implode(' ', array_map(static fn (string $name): string => sprintf(':%s="%s"', $name, $name), array_keys($props)))
        . ' /></tbody></table>',
    )->render(['columns' => $host->getTable()->getVisibleColumns(), 'query' => $host->getAllTableSummaryQuery(), ...$props]));
}

it('renders no summary band when no column is summarized', function (): void {
    $crawler = summarizedTableCrawler([TextColumn::make('title'), TextColumn::make('plays')]);

    expect($crawler->filter('tr.sy-ta-summary-row')->count())->toBe(0)
        ->and($crawler->filter('tr.sy-ta-summary-header-row')->count())->toBe(0)
        ->and($crawler->filter('tbody tr[id*=".table.records."]')->count())->toBe(5);
});

it('renders the all-table row alone on a single page', function (): void {
    $row = summarizedTableCrawler([
        TextColumn::make('title'),
        TextColumn::make('plays')->summarize(Sum::make()),
    ])->filter('tr.sy-ta-summary-row');

    expect($row->count())->toBe(1)
        ->and($row->attr('class'))->toContain('sy-striped')
        ->and($row->filter('th[scope="row"]')->text())->toBe('Summary')
        ->and($row->filter('td')->last()->text())->toContain('150');
});

it('renders the header row, the page row and the all-table row across pages', function (): void {
    $crawler = summarizedTableCrawler([
        TextColumn::make('title'),
        TextColumn::make('plays')->summarize(Sum::make()),
    ], fn (Table $table): Table => $table->paginated([2]));

    $header = $crawler->filter('tr.sy-ta-summary-header-row');
    $rows = $crawler->filter('tr.sy-ta-summary-row');

    expect($header->count())->toBe(1)
        ->and($header->attr('class'))->toContain('sy-striped')
        ->and($rows->count())->toBe(2)
        ->and($rows->each(fn (Crawler $row): string => $row->filter('th[scope="row"]')->text()))
        ->toBe(['This page', 'All songs'])
        ->and($rows->each(fn (Crawler $row): ?string => $row->attr('class')))
        ->toBe(['sy-ta-row sy-ta-summary-row', 'sy-ta-row sy-ta-summary-row'])
        ->and($rows->first()->filter('td')->last()->text())->toContain('30')
        ->and($rows->last()->filter('td')->last()->text())->toContain('150');
});

it('renders no page row on an overflow page, where the page query has nothing to read', function (): void {
    $host = summarizedTableHost([
        TextColumn::make('title')->searchable(isIndividual: true),
        TextColumn::make('plays')->summarize(Sum::make()),
    ], fn (Table $table): Table => $table->paginated([2]));

    // The page number is clamped against `PHP_INT_MAX / perPage` alone, so a
    // page past the end holds no records while the paginator still reports
    // pages; the table itself still renders, because a column is individually
    // searchable. The page row is what the page query proves, not what the
    // paginator claims — without that, the row would summarize a null query.
    $host->tablePage = 99;

    $crawler = new Crawler(renderToHtml($host->getTable()));
    $rows = $crawler->filter('tr.sy-ta-summary-row');

    expect($crawler->filter('table.sy-ta-table')->count())->toBe(1)
        ->and($crawler->filter('tbody tr[id*=".table.records."]')->count())->toBe(0)
        ->and($crawler->filter('tr.sy-ta-summary-header-row')->count())->toBe(0)
        ->and($rows->count())->toBe(1)
        ->and($rows->filter('th[scope="row"]')->text())->toBe('Summary')
        ->and($rows->filter('td')->last()->text())->toContain('150');
});

it('renders no page row under summaries(pageCondition: false)', function (): void {
    $crawler = summarizedTableCrawler([
        TextColumn::make('title'),
        TextColumn::make('plays')->summarize(Sum::make()),
    ], fn (Table $table): Table => $table->paginated([2])->summaries(false));

    $rows = $crawler->filter('tr.sy-ta-summary-row');

    expect($crawler->filter('tr.sy-ta-summary-header-row')->count())->toBe(0)
        ->and($rows->count())->toBe(1)
        ->and($rows->filter('th[scope="row"]')->text())->toBe('Summary')
        ->and($rows->attr('class'))->toContain('sy-striped');
});

it('renders no all-table row under summaries(allTableCondition: false)', function (): void {
    $crawler = summarizedTableCrawler([
        TextColumn::make('title'),
        TextColumn::make('plays')->summarize(Sum::make()),
    ], fn (Table $table): Table => $table->paginated([2])->summaries(allTableCondition: false));

    $rows = $crawler->filter('tr.sy-ta-summary-row');

    expect($crawler->filter('tr.sy-ta-summary-header-row')->count())->toBe(1)
        ->and($rows->count())->toBe(1)
        ->and($rows->filter('th[scope="row"]')->text())->toBe('This page');
});

it('keeps every summary row inside the body and out of the foot', function (): void {
    $crawler = summarizedTableCrawler([
        TextColumn::make('title'),
        TextColumn::make('plays')->summarize(Sum::make()),
    ], fn (Table $table): Table => $table->paginated([2]));

    expect($crawler->filter('tfoot tr')->count())->toBe(0)
        ->and($crawler->filter('tbody tr.sy-ta-summary-row')->count())->toBe(2)
        ->and($crawler->filter('tbody tr.sy-ta-summary-header-row')->count())->toBe(1);
});

it('renders no summary row, and asks for no figure, while reordering', function (): void {
    $recorder = new RecordingMiddleware();
    $host = summarizedTableHost([
        TextColumn::make('title'),
        TextColumn::make('plays')->summarize(Sum::make()),
    ], fn (Table $table): Table => $table->reorderable('position'), grouping: 'mood', recorder: $recorder);

    $summarized = new Crawler(renderToHtml($host->getTable()));
    $aggregates = array_filter($recorder->statements(), static fn (string $sql): bool => str_contains($sql, 'SUM('));

    $recorder->reset();
    $host->isTableReordering = true;

    $reordering = new Crawler(renderToHtml($host->getTable()));

    // The figure is not merely hidden: nothing asks the database for it, since
    // an aggregate whose row will not render is a statement for nothing.
    expect($aggregates)->not->toBeEmpty()
        ->and($summarized->filter('tr.sy-ta-summary-row')->count())->toBeGreaterThan(0)
        ->and($reordering->filter('tr.sy-ta-summary-row')->count())->toBe(0)
        ->and($reordering->filter('tr.sy-ta-summary-header-row')->count())->toBe(0)
        ->and(array_filter($recorder->statements(), static fn (string $sql): bool => str_contains($sql, 'SUM(')))->toBeEmpty();
});

it('spans the heading cell to the first summarized column', function (): void {
    $heading = summarizedTableCrawler([
        TextColumn::make('title'),
        TextColumn::make('mood'),
        TextColumn::make('plays')->summarize(Sum::make()),
    ])->filter('tr.sy-ta-summary-row th.sy-ta-summary-row-heading-cell');

    expect($heading->attr('colspan'))->toBe('2');
});

it('emits no colspan when the second column is summarized', function (): void {
    $heading = summarizedTableCrawler([
        TextColumn::make('title'),
        TextColumn::make('plays')->summarize(Sum::make()),
    ])->filter('tr.sy-ta-summary-row th.sy-ta-summary-row-heading-cell');

    expect($heading->attr('colspan'))->toBeNull();
});

it('spans every column when only the first one is summarized', function (): void {
    // Vendor's arithmetic drops a first-column summarizer: the span starts at the
    // heading's own column and only a *later* summarized column closes it.
    $row = summarizedTableCrawler([
        TextColumn::make('title')->summarize(Count::make()),
        TextColumn::make('mood'),
        TextColumn::make('plays'),
    ])->filter('tr.sy-ta-summary-row');

    expect($row->filter('th, td')->count())->toBe(1)
        ->and($row->filter('th.sy-ta-summary-row-heading-cell')->attr('colspan'))->toBe('3')
        ->and($row->filter('div.sy-ta-text-summary')->count())->toBe(0);
});

it('emits a cell per column and no colspan in the extra-heading mode', function (): void {
    $row = summaryRowCrawler(summarizedTableHost([
        TextColumn::make('title'),
        TextColumn::make('mood'),
        TextColumn::make('plays')->summarize(Sum::make()),
    ]), ['extraHeadingColumn' => true])->filter('tr.sy-ta-summary-row');

    expect($row->filter('th[scope="row"]')->count())->toBe(1)
        ->and($row->filter('th[scope="row"]')->attr('colspan'))->toBeNull()
        ->and($row->filter('th[scope="row"]')->text())->toBe('Summary')
        ->and($row->filter('th, td')->count())->toBe(4)
        ->and($row->filter('td')->last()->text())->toContain('150');
});

it('labels the summary row with a row header and its figures with cells', function (): void {
    $row = summarizedTableCrawler([
        TextColumn::make('title'),
        TextColumn::make('mood'),
        TextColumn::make('plays')->summarize(Sum::make()),
    ])->filter('tr.sy-ta-summary-row');

    expect($row->filter('th')->count())->toBe(1)
        ->and($row->filter('th')->attr('scope'))->toBe('row')
        ->and($row->filter('td')->count())->toBe(1)
        ->and($row->filter('td')->attr('scope'))->toBeNull();
});

it('makes only a labelled summary header cell a column header', function (): void {
    $cells = summarizedTableCrawler([
        TextColumn::make('title'),
        TextColumn::make('mood'),
        TextColumn::make('plays')->summarize(Sum::make()),
    ], fn (Table $table): Table => $table->paginated([2]))->filter('tr.sy-ta-summary-header-row > *');

    expect($cells->count())->toBe(3)
        ->and($cells->eq(0)->nodeName())->toBe('th')
        ->and($cells->eq(0)->attr('scope'))->toBe('col')
        ->and($cells->eq(0)->text())->toBe('Summary')
        ->and($cells->eq(1)->nodeName())->toBe('td')
        ->and($cells->eq(1)->attr('scope'))->toBeNull()
        ->and($cells->eq(1)->text())->toBe('')
        ->and($cells->eq(2)->nodeName())->toBe('th')
        ->and($cells->eq(2)->attr('scope'))->toBe('col')
        ->and($cells->eq(2)->text())->toBe('Plays');
});

it('aligns the first summary heading to the start whatever its column asks for', function (): void {
    $crawler = summarizedTableCrawler([
        TextColumn::make('title')->alignEnd(),
        TextColumn::make('plays')->summarize(Sum::make()),
    ], fn (Table $table): Table => $table->paginated([2]));

    expect($crawler->filter('tr.sy-ta-summary-row th')->attr('class'))->toContain('sy-align-start')
        ->and($crawler->filter('tr.sy-ta-summary-row th')->attr('class'))->not->toContain('sy-align-end')
        ->and($crawler->filter('tr.sy-ta-summary-header-row > *')->first()->attr('class'))->toContain('sy-align-start');
});

it('carries the column alignment on a summary value cell and on its header cell', function (): void {
    $crawler = summarizedTableCrawler([
        TextColumn::make('title'),
        TextColumn::make('plays')->alignEnd()->summarize(Sum::make()),
    ], fn (Table $table): Table => $table->paginated([2]));

    expect($crawler->filter('tr.sy-ta-summary-row')->first()->filter('td')->attr('class'))->toContain('sy-align-end')
        ->and($crawler->filter('tr.sy-ta-summary-header-row > *')->last()->attr('class'))->toContain('sy-align-end');
});

it('carries a hidden-from column responsive class onto its summary cells', function (): void {
    // The port's divergence from vendor, which emits none: without the pair the
    // summary row renders one cell wider than the header row below `md`.
    $crawler = summarizedTableCrawler([
        TextColumn::make('title'),
        TextColumn::make('plays')->hiddenFrom('md')->summarize(Sum::make()),
        TextColumn::make('mood')->visibleFrom('lg'),
    ], fn (Table $table): Table => $table->paginated([2]));

    expect($crawler->filter('tr.sy-ta-summary-row')->first()->filter('td')->first()->attr('class'))
        ->toContain('md:sy-ta-hidden')
        ->and($crawler->filter('tr.sy-ta-summary-row')->first()->filter('td')->last()->attr('class'))
        ->toContain('lg:sy-ta-visible')
        ->and($crawler->filter('tr.sy-ta-summary-header-row > *')->eq(1)->attr('class'))
        ->toContain('md:sy-ta-hidden');
});

it('closes every group with a summary row of its own', function (): void {
    $crawler = summarizedTableCrawler([
        TextColumn::make('mood'),
        TextColumn::make('plays')->summarize(Sum::make()),
    ], fn (Table $table): Table => $table->groups(['mood']), grouping: 'mood');

    $rows = $crawler->filter('tr.sy-ta-summary-row');

    // Three groups, then the all-table row; the page row cannot render, since
    // five records fit on one page.
    expect($rows->count())->toBe(4)
        ->and($rows->each(fn (Crawler $row): string => $row->filter('th[scope="row"]')->text()))
        ->toBe(['calm summary', 'bright summary', 'dark summary', 'Summary'])
        ->and($rows->eq(0)->filter('td')->text())->toContain('30')
        ->and($rows->eq(1)->filter('td')->text())->toContain('70')
        ->and($rows->eq(2)->filter('td')->text())->toContain('50')
        ->and($rows->eq(3)->filter('td')->text())->toContain('150');
});

/*
 * One render runs several batches — the page one, the all-table one and one per
 * group — and a summarizer's alias has to survive all of them: the band's rows are
 * rendered after the loop that fires the group batches, so an alias re-minted by
 * the last of those is the one the all-table row reads back.
 */
it('keeps one alias per summarizer across every batch of one render', function (): void {
    $rows = summarizedTableCrawler([
        TextColumn::make('mood'),
        // Hidden on every scoped batch and visible on the unscoped one, which is
        // exactly what shifts an ordinal counted over the visible set alone.
        TextColumn::make('plays')->summarize(Sum::make()->hidden(fn (QueryBuilder $query): bool => count($query->getParameters()) > 0)),
        TextColumn::make('position')->summarize(Count::make()),
    ], fn (Table $table): Table => $table->groups(['mood']), grouping: 'mood')->filter('tr.sy-ta-summary-row');

    expect($rows->each(fn (Crawler $row): string => $row->text()))->toBe([
        'calm summary Count2',
        'bright summary Count2',
        'dark summary Count1',
        'Summary Sum150 Count5',
    ]);
});

it('keys each group summary row by the group it closes', function (): void {
    $host = summarizedTableHost([
        TextColumn::make('mood'),
        TextColumn::make('plays')->summarize(Sum::make()),
    ], fn (Table $table): Table => $table->groups(['mood']), grouping: 'mood');

    $hostId = $host->getTableHostId();
    $rows = new Crawler(renderToHtml($host->getTable()))->filter('tr.sy-ta-summary-row[id]');

    expect($rows->count())->toBe(4)
        ->and($rows->each(fn (Crawler $row): ?string => $row->attr('id')))
        ->toBe([
            $hostId . '.table.group-summary.calm',
            $hostId . '.table.group-summary.bright',
            $hostId . '.table.group-summary.dark',
            $hostId . '.table.summary.all',
        ]);
});

/**
 * A summarized table over the two edges of a group key: two records with no mood
 * at all, and two whose mood carries a space.
 *
 * @param (Closure(Table): Table)|null $configure
 */
function moodEdgeSummaryCrawler(?Closure $configure = null): Crawler
{
    $entityManager = EntityManagerFactory::create();

    EntityManagerFactory::seed(
        $entityManager,
        new Song(id: 1, title: 'Airbag', plays: 10, mood: 'slow burn', position: 1),
        new Song(id: 2, title: 'Airport', plays: 20, mood: 'slow burn', position: 2),
        new Song(id: 3, title: 'Jóga', plays: 30, position: 3),
        new Song(id: 4, title: 'Hyperballad', plays: 40, position: 4),
    );

    $host = new TableHost(fn (Table $table): Table => ($configure ?? static fn (Table $table): Table => $table)(
        $table
            ->query($entityManager->createQueryBuilder()->select('song')->from(Song::class, 'song')->orderBy('song.id', 'ASC'))
            ->columns([TextColumn::make('mood'), TextColumn::make('plays')->summarize(Sum::make())])
            ->groups(['mood']),
    ));

    $host->tableGrouping = 'mood';

    return new Crawler(renderToHtml($host->getTable()));
}

it('closes a group with no heading with no summary row at all', function (): void {
    $crawler = moodEdgeSummaryCrawler();

    // The blank group keeps its header row and its records; what it does not get
    // is a figure published under a bare " summary", which is vendor's own gate.
    expect($crawler->filter('tr.sy-ta-group-header-row')->count())->toBe(2)
        ->and($crawler->filter('tr.sy-ta-summary-row')->each(fn (Crawler $row): string => $row->filter('th[scope="row"]')->text()))
        ->toBe(['slow burn summary', 'Summary']);
});

it('keeps the blank group row under groupsOnly(), where the row is the group', function (): void {
    $crawler = moodEdgeSummaryCrawler(fn (Table $table): Table => $table->groupsOnly());

    // Under groupsOnly() the summary row carries the heading rather than sitting
    // under one, so gating it on a filled title would take the group off the page.
    expect($crawler->filter('tr.sy-ta-summary-row')->each(fn (Crawler $row): string => $row->filter('th[scope="row"]')->text()))
        ->toBe(['slow burn', '', 'Summary']);
});

it('percent-encodes a group key carrying whitespace in the summary row id', function (): void {
    $ids = moodEdgeSummaryCrawler()->filter('tr.sy-ta-summary-row[id]')->each(fn (Crawler $row): ?string => $row->attr('id'));

    expect($ids)->toHaveCount(2)
        ->and($ids[0])->toEndWith('.table.group-summary.slow%20burn')
        ->and($ids[1])->toEndWith('.table.summary.all')
        ->and(array_filter($ids, fn (?string $id): bool => str_contains((string) $id, ' ')))->toBe([]);
});

it('opens a group with its header row and never with a summary row', function (): void {
    $rows = summarizedTableCrawler([
        TextColumn::make('mood'),
        TextColumn::make('plays')->summarize(Sum::make()),
    ], fn (Table $table): Table => $table->groups(['mood']), grouping: 'mood')->filter('tbody > tr');

    expect($rows->first()->attr('class'))->toContain('sy-ta-group-header-row')
        ->and($rows->eq(1)->attr('id'))->toContain('.table.records.');
});

it('holds the trailing group summary back while the group runs onto the next page', function (): void {
    $spanning = summarizedTableCrawler([
        TextColumn::make('mood'),
        TextColumn::make('plays')->summarize(Sum::make()),
    ], fn (Table $table): Table => $table->groups(['mood'])->paginated([1]), grouping: 'mood');

    // Page one holds the first of two calm songs, so the group is unfinished and
    // no figure may be published under its heading; the closed-group twin below
    // is the same table with the whole group on the page.
    $closed = summarizedTableCrawler([
        TextColumn::make('mood'),
        TextColumn::make('plays')->summarize(Sum::make()),
    ], fn (Table $table): Table => $table->groups(['mood'])->paginated([2]), grouping: 'mood');

    expect($spanning->filter('tr.sy-ta-summary-row[id*=".group-summary."]')->count())->toBe(0)
        ->and($closed->filter('tr.sy-ta-summary-row[id*=".group-summary."]')->count())->toBe(1)
        ->and($closed->filter('tr.sy-ta-summary-row[id*=".group-summary."]')->filter('th')->text())->toBe('calm summary');
});

it('stripes the summary header row and the lone all-table row alone', function (): void {
    $paginated = summarizedTableCrawler([
        TextColumn::make('title'),
        TextColumn::make('plays')->summarize(Sum::make()),
    ], fn (Table $table): Table => $table->paginated([2]));

    $single = summarizedTableCrawler([
        TextColumn::make('title'),
        TextColumn::make('plays')->summarize(Sum::make()),
    ]);

    expect($paginated->filter('tr.sy-ta-summary-header-row')->attr('class'))->toContain('sy-striped')
        ->and($paginated->filter('tr.sy-ta-summary-row.sy-striped')->count())->toBe(0)
        ->and($single->filter('tr.sy-ta-summary-row')->attr('class'))->toContain('sy-striped');
});

it('renders a sum as a labelled text summary', function (): void {
    $summary = summarizedTableCrawler([
        TextColumn::make('title'),
        TextColumn::make('plays')->summarize(Sum::make()),
    ])->filter('tr.sy-ta-summary-row div.sy-ta-text-summary');

    expect($summary->count())->toBe(1)
        ->and($summary->filter('span')->count())->toBe(2)
        ->and($summary->filter('span.sy-ta-text-summary-label')->text())->toBe('Sum')
        ->and($summary->filter('span.sy-ta-text-summary-label')->attr('class'))->not->toContain('sy-sr-only')
        ->and($summary->filter('span')->last()->text())->toBe('150');
});

it('hides a summarizer label behind the screen-reader class in all three markups', function (): void {
    $text = summarizedTableCrawler([
        TextColumn::make('title'),
        TextColumn::make('plays')->summarize(Sum::make()->hiddenLabel()),
    ])->filter('span.sy-ta-text-summary-label');

    $range = summarizedTableCrawler([
        TextColumn::make('mood'),
        TextColumn::make('plays')->summarize(Range::make()->label('Plays between')->hiddenLabel()),
    ])->filter('span.sy-ta-range-summary-label');

    $values = summarizedTableCrawler([
        TextColumn::make('title'),
        TextColumn::make('mood')->summarize(Values::make()->label('Moods')->hiddenLabel()),
    ])->filter('span.sy-ta-values-summary-label');

    // Vendor honours `hiddenLabel()` in the text markup alone, and the port
    // honours it in every one, so the range and the values arms are asserted
    // here rather than taken from the text one.
    expect($text->text())->toBe('Sum')
        ->and($text->attr('class'))->toBe('sy-ta-text-summary-label sy-sr-only')
        ->and($range->text())->toBe('Plays between')
        ->and($range->attr('class'))->toBe('sy-ta-range-summary-label sy-sr-only')
        ->and($values->text())->toBe('Moods')
        ->and($values->attr('class'))->toBe('sy-ta-values-summary-label sy-sr-only');
});

it('renders an average and a count under their default labels', function (): void {
    $summaries = summarizedTableCrawler([
        TextColumn::make('title'),
        TextColumn::make('plays')->summarize([Average::make(), Count::make()]),
    ])->filter('tr.sy-ta-summary-row td div.sy-ta-text-summary');

    expect($summaries->count())->toBe(2)
        ->and($summaries->eq(0)->filter('span.sy-ta-text-summary-label')->text())->toBe('Average')
        ->and($summaries->eq(0)->filter('span')->last()->text())->toBe('30')
        ->and($summaries->eq(1)->filter('span.sy-ta-text-summary-label')->text())->toBe('Count')
        ->and($summaries->eq(1)->filter('span')->last()->text())->toBe('5');
});

it('joins a range with a hyphen only while both of its ends are filled', function (): void {
    $pair = summarizedTableCrawler([
        TextColumn::make('mood'),
        TextColumn::make('plays')->summarize(Range::make()->label('Plays between')),
    ])->filter('div.sy-ta-range-summary');

    // Every seeded song shares one tempo, so the preset collapses the pair to a
    // single end and the separator has nothing to sit between.
    $collapsed = summarizedTableCrawler([
        TextColumn::make('mood'),
        TextColumn::make('tempo')->summarize(Range::make()->minimalTextualDifference()),
    ])->filter('div.sy-ta-range-summary');

    expect($pair->filter('span.sy-ta-range-summary-label')->text())->toBe('Plays between')
        ->and($pair->filter('span')->last()->text())->toBe('10 - 50')
        ->and($collapsed->filter('span.sy-ta-range-summary-label')->count())->toBe(0)
        ->and($collapsed->filter('span')->text())->toBe('medium');
});

it('renders a values summary as a bulleted list and drops the bullets under bulleted(false)', function (): void {
    $bulleted = summarizedTableCrawler([
        TextColumn::make('title'),
        TextColumn::make('mood')->summarize(Values::make()),
    ])->filter('div.sy-ta-values-summary');

    $plain = summarizedTableCrawler([
        TextColumn::make('title'),
        TextColumn::make('mood')->summarize(Values::make()->bulleted(false)),
    ])->filter('div.sy-ta-values-summary');

    $values = $bulleted->filter('ul.sy-bulleted > li')->each(fn (Crawler $item): string => $item->text());
    sort($values);

    expect($bulleted->filter('ul.sy-bulleted')->count())->toBe(1)
        ->and($values)->toBe(['bright', 'calm', 'dark'])
        ->and($plain->filter('ul')->count())->toBe(1)
        ->and($plain->filter('ul')->attr('class'))->toBeNull();
});

it('emits its leading placeholder cells before the heading cell', function (): void {
    $cells = summarizedTableCrawler([
        TextColumn::make('title'),
        TextColumn::make('plays')->summarize(Sum::make()),
    ], fn (Table $table): Table => $table
        ->recordActions([Action::make('edit')])
        ->recordActionsPosition(RecordActionsPosition::BeforeCells)
        ->toolbarActions([BulkAction::make('archive')])
        ->recordCheckboxPosition(RecordCheckboxPosition::BeforeCells))->filter('tr.sy-ta-summary-row > *');

    expect($cells->count())->toBe(4)
        ->and($cells->eq(0)->nodeName())->toBe('td')
        ->and($cells->eq(0)->text())->toBe('')
        ->and($cells->eq(1)->nodeName())->toBe('td')
        ->and($cells->eq(1)->text())->toBe('')
        ->and($cells->eq(2)->nodeName())->toBe('th')
        ->and($cells->eq(2)->text())->toBe('Summary');
});

it('emits its trailing placeholder cells after the value cells', function (): void {
    $cells = summarizedTableCrawler([
        TextColumn::make('title'),
        TextColumn::make('plays')->summarize(Sum::make()),
    ], fn (Table $table): Table => $table
        ->recordActions([Action::make('edit')])
        ->recordActionsPosition(RecordActionsPosition::AfterCells)
        ->toolbarActions([BulkAction::make('archive')])
        ->recordCheckboxPosition(RecordCheckboxPosition::AfterCells))->filter('tr.sy-ta-summary-row > *');

    expect($cells->count())->toBe(4)
        ->and($cells->eq(1)->text())->toContain('150')
        ->and($cells->eq(2)->nodeName())->toBe('td')
        ->and($cells->eq(2)->text())->toBe('')
        ->and($cells->eq(3)->nodeName())->toBe('td')
        ->and($cells->eq(3)->text())->toBe('');
});

it('keeps every summary row exactly as wide as the header row', function (RecordActionsPosition $actions, RecordCheckboxPosition $checkbox): void {
    // The placeholder logic has eight combinations and a wrong count renders as a
    // subtly shifted table nobody notices in a screenshot, so the assertion is the
    // header row's own cell count rather than a literal.
    $crawler = summarizedTableCrawler([
        TextColumn::make('title'),
        TextColumn::make('mood'),
        TextColumn::make('plays')->summarize(Sum::make()),
    ], fn (Table $table): Table => $table
        ->paginated([2])
        ->recordActions([Action::make('edit')])
        ->recordActionsPosition($actions)
        ->toolbarActions([BulkAction::make('archive')])
        ->recordCheckboxPosition($checkbox));

    $headerCells = $crawler->filter('thead th')->count();

    expect($headerCells)->toBe(5)
        ->and($crawler->filter('tr.sy-ta-summary-header-row > *')->count())->toBe($headerCells)
        ->and($crawler->filter('tr.sy-ta-summary-row')->each(fn (Crawler $row): int => $row->filter('th, td')->count()))
        ->toBe([$headerCells - 1, $headerCells - 1]);
})->with(function (): iterable {
    // `AfterContent` is excluded because it renders no cell at all in the table
    // layout — it belongs to the content one, which is M4 · 7's.
    foreach ([RecordActionsPosition::BeforeCells, RecordActionsPosition::BeforeColumns, RecordActionsPosition::AfterColumns, RecordActionsPosition::AfterCells] as $actions) {
        foreach (RecordCheckboxPosition::cases() as $checkbox) {
            yield sprintf('%s actions, %s checkbox', $actions->name, $checkbox->name) => [$actions, $checkbox];
        }
    }
});

it('renders every summarizer of the shipped fixture its heading cell does not absorb', function (): void {
    $rows = new Crawler(renderToHtml($this::getContainer()->get(SummarySongsTable::class)->getTable()))
        ->filter('tr.sy-ta-summary-row');

    // The fixture's `Range` sits on the first column, which is the heading cell's
    // own — vendor's span arithmetic drops a first-column summarizer, and the
    // range's markup is exercised on a later column above.
    expect($rows->count())->toBe(2)
        ->and($rows->last()->filter('th[scope="row"]')->text())->toBe('All songs')
        ->and($rows->last()->filter('div.sy-ta-range-summary')->count())->toBe(0)
        ->and($rows->last()->filter('div.sy-ta-text-summary')->each(fn (Crawler $summary): string => $summary->text()))
        ->toBe(['Sum150', 'Average30', 'Rows5'])
        ->and($rows->last()->filter('div.sy-ta-values-summary ul.sy-bulleted > li')->count())->toBe(3);
});

it('reads the shipped fixture page row from the page alone', function (): void {
    $row = new Crawler(renderToHtml($this::getContainer()->get(SummarySongsTable::class)->getTable()))
        ->filter('tr.sy-ta-summary-row')->first();

    expect($row->filter('th[scope="row"]')->text())->toBe('This page')
        ->and($row->filter('div.sy-ta-text-summary')->each(fn (Crawler $summary): string => $summary->text()))
        ->toBe(['Sum30', 'Average15', 'Rows2'])
        ->and($row->filter('div.sy-ta-values-summary ul > li')->each(fn (Crawler $item): string => $item->text()))
        ->toBe(['calm']);
});

it('renders the shipped fixture range once a heading cell of its own frees its column', function (): void {
    // The same fixture with a dedicated heading cell: every column now carries
    // its own cell, so the range the table layout absorbs renders its two ends.
    $row = summaryRowCrawler($this::getContainer()->get(SummarySongsTable::class), ['extraHeadingColumn' => true])
        ->filter('tr.sy-ta-summary-row');

    expect($row->filter('th, td')->count())->toBe(5)
        ->and($row->filter('div.sy-ta-range-summary span')->text())->toBe('A - J')
        ->and($row->filter('div.sy-ta-text-summary')->each(fn (Crawler $summary): string => $summary->text()))
        ->toBe(['Sum150', 'Average30', 'Rows5']);
});
