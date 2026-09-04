<?php

declare(strict_types=1);

use Doctrine\ORM\EntityManager;
use Doctrine\ORM\QueryBuilder;
use Sylament\Tables\Columns\Column;
use Sylament\Tables\Columns\Summarizers\Average;
use Sylament\Tables\Columns\Summarizers\Count;
use Sylament\Tables\Columns\Summarizers\Range;
use Sylament\Tables\Columns\Summarizers\Sum;
use Sylament\Tables\Columns\Summarizers\Values;
use Sylament\Tables\Columns\TextColumn;
use Sylament\Tables\Grouping\Group;
use Sylament\Tables\Table;
use Sylament\Tables\Tests\Fixtures\Concerns\TableHost;
use Sylament\Tables\Tests\Fixtures\Doctrine\Entity\Address;
use Sylament\Tables\Tests\Fixtures\Doctrine\Entity\Artist;
use Sylament\Tables\Tests\Fixtures\Doctrine\Entity\Book;
use Sylament\Tables\Tests\Fixtures\Doctrine\Entity\CompositePair;
use Sylament\Tables\Tests\Fixtures\Doctrine\Entity\Genre;
use Sylament\Tables\Tests\Fixtures\Doctrine\Entity\Song;
use Sylament\Tables\Tests\Fixtures\Doctrine\EntityManagerFactory;
use Sylament\Tables\Tests\Fixtures\Doctrine\RecordingMiddleware;

/**
 * Six songs summing to 210, in ascending play order across two artists — so a page
 * of the first two sums to 30 where the whole table sums to 210, and the two numbers
 * can never be mistaken for each other.
 */
function summaryStateSongs(?RecordingMiddleware $recorder = null): EntityManager
{
    $entityManager = EntityManagerFactory::create($recorder);

    $radiohead = new Artist(1, 'Radiohead', address: new Address('Abbey Road'));
    $bjork = new Artist(2, 'Björk', address: new Address('Laugavegur'));

    EntityManagerFactory::seed(
        $entityManager,
        $radiohead,
        $bjork,
        new Song(id: 1, title: 'Airbag', plays: 10, artist: $radiohead),
        new Song(id: 2, title: 'Let Down', plays: 20, artist: $radiohead),
        new Song(id: 3, title: 'No Surprises', plays: 30, artist: $radiohead),
        new Song(id: 4, title: 'Jóga', plays: 40, artist: $bjork),
        new Song(id: 5, title: 'Hyperballad', plays: 50, artist: $bjork),
        new Song(id: 6, title: 'Bachelorette', plays: 60, artist: $bjork),
    );

    return $entityManager;
}

/**
 * @param array<Column> $columns
 */
function summaryStateHost(EntityManager $entityManager, array $columns, int|string|null $recordsPerPage = 2, int $page = 1): TableHost
{
    $host = new TableHost(function (Table $table) use ($entityManager, $columns, $recordsPerPage): Table {
        $table = $table
            ->query($entityManager->createQueryBuilder()->select('song')->from(Song::class, 'song')->orderBy('song.id', 'ASC'))
            ->columns($columns);

        return $recordsPerPage === null ? $table->paginated(false) : $table->paginated([$recordsPerPage]);
    });

    $host->tablePage = $page;

    return $host;
}

it('takes the all-table summary query from the filtered query, and reads it unsorted and unpaged', function (): void {
    $recorder = new RecordingMiddleware();
    $host = summaryStateHost(summaryStateSongs($recorder), [TextColumn::make('plays')->summarize(Sum::make())]);
    $query = $host->getAllTableSummaryQuery();

    $recorder->reset();

    expect($query)->toBeInstanceOf(QueryBuilder::class)
        ->and($query?->getMaxResults())->toBeNull()
        ->and($host->getTableSummarySelectedState($query))->toBe(['sy_summary_0' => 210])
        ->and($recorder->statements())->toHaveCount(1)
        ->and($recorder->statements()[0])->not->toContain('ORDER BY')
        ->and($recorder->statements()[0])->not->toContain('LIMIT');
});

it('summarizes only what the filters and the searches left', function (): void {
    $host = summaryStateHost(summaryStateSongs(), [TextColumn::make('title')->searchable(), TextColumn::make('plays')->summarize(Sum::make())]);
    $host->tableSearch = 'Airbag';

    expect($host->getTableSummarySelectedState($host->getAllTableSummaryQuery()))->toBe(['sy_summary_0' => 10]);
});

/*
 * The one case that catches the trap the whole design turns on: a LIMIT bounds an
 * aggregate's result row rather than the aggregated set, so a page summary written
 * with one reads the all-table figure on every page and never errors.
 */
it('summarizes the page over the page keys, to a different figure than the whole table', function (): void {
    $host = summaryStateHost(summaryStateSongs(), [TextColumn::make('plays')->summarize(Sum::make())]);

    expect($host->getTableSummarySelectedState($host->getPageTableSummaryQuery()))->toBe(['sy_summary_0' => 30])
        ->and($host->getTableSummarySelectedState($host->getAllTableSummaryQuery()))->toBe(['sy_summary_0' => 210]);
});

it('narrows the page summary to the keys of the page being shown', function (): void {
    $host = summaryStateHost(summaryStateSongs(), [TextColumn::make('plays')->summarize(Sum::make())], page: 2);
    $query = $host->getPageTableSummaryQuery();

    expect($query?->getDQL())->toContain('IN (:sylament_summary_keys)')
        ->and($query?->getParameter('sylament_summary_keys')?->getValue())->toBe(['3', '4'])
        ->and($host->getTableSummarySelectedState($query))->toBe(['sy_summary_0' => 70]);
});

it('summarizes the whole table on a page holding all of it', function (): void {
    $host = summaryStateHost(summaryStateSongs(), [TextColumn::make('plays')->summarize(Sum::make())], recordsPerPage: 'all');

    expect($host->getTableSummarySelectedState($host->getPageTableSummaryQuery()))->toBe(['sy_summary_0' => 210]);
});

/*
 * An empty page would bind an empty key list, and `IN ()` is a syntax error on most
 * platforms — the same guard the aggregate batch already carries.
 */
it('answers no page summary query for an empty page', function (): void {
    $host = summaryStateHost(EntityManagerFactory::create(), [TextColumn::make('plays')->summarize(Sum::make())]);

    expect($host->getPageTableSummaryQuery())->toBeNull()
        ->and($host->getTableSummarySelectedState(null))->toBe([]);
});

it('answers no page summary query for an unpaginated table', function (): void {
    $host = summaryStateHost(summaryStateSongs(), [TextColumn::make('plays')->summarize(Sum::make())], recordsPerPage: null);

    expect($host->getPageTableSummaryQuery())->toBeNull()
        ->and($host->getAllTableSummaryQuery())->toBeInstanceOf(QueryBuilder::class);
});

it('names the record class own identifier in the page narrowing', function (): void {
    $entityManager = EntityManagerFactory::create();

    EntityManagerFactory::seed($entityManager, new Book('a', 'Dune'), new Book('b', 'Ubik'), new Book('c', 'Solaris'));

    $host = new TableHost(fn (Table $table): Table => $table
        ->query($entityManager->createQueryBuilder()->select('book')->from(Book::class, 'book')->orderBy('book.code', 'ASC'))
        ->columns([TextColumn::make('title')->summarize(Count::make())])
        ->paginated([2]));

    expect($host->getPageTableSummaryQuery()?->getDQL())->toContain('book.code IN (:sylament_summary_keys)')
        ->and($host->getTableSummarySelectedState($host->getPageTableSummaryQuery()))->toBe(['sy_summary_0' => 2]);
});

/*
 * A composite identifier is refused a page earlier and louder than a summary: keying
 * the page's own records is what throws, so the page summary needs no arm of its own
 * — while the all-table row, which keys nothing, still reads its figure.
 */
it('leaves the composite identifier refusal to the page it cannot key', function (): void {
    $entityManager = EntityManagerFactory::create();

    EntityManagerFactory::seed($entityManager, new CompositePair(1, 1, 'first'), new CompositePair(1, 2, 'second'));

    $host = new TableHost(fn (Table $table): Table => $table
        ->query($entityManager->createQueryBuilder()->select('pair')->from(CompositePair::class, 'pair')->orderBy('pair.a', 'ASC'))
        ->columns([TextColumn::make('label')->summarize(Count::make())])
        ->paginated([1]));

    expect($host->getTableSummarySelectedState($host->getAllTableSummaryQuery()))->toBe(['sy_summary_0' => 2])
        ->and(fn (): ?QueryBuilder => $host->getPageTableSummaryQuery())
        ->toThrow(LogicException::class, 'composite identifier');
});

it('answers no summary query at all on the records lane', function (): void {
    $host = new TableHost(fn (Table $table): Table => $table
        ->records(fn (): array => [['id' => '1', 'plays' => 10]])
        ->columns([TextColumn::make('plays')->summarize(Sum::make())]));

    expect($host->getAllTableSummaryQuery())->toBeNull()
        ->and($host->getPageTableSummaryQuery())->toBeNull();
});

/*
 * The batch is the reason the tier exists: every batchable summarizer of every visible
 * column rides one statement, each under its own deterministic alias.
 */
it('reads every batchable summarizer of every column in one statement', function (): void {
    $recorder = new RecordingMiddleware();
    $host = summaryStateHost(summaryStateSongs($recorder), [
        TextColumn::make('plays')->summarize([Sum::make(), Average::make()]),
        TextColumn::make('id')->summarize(Count::make()),
    ], recordsPerPage: 'all');

    $query = $host->getAllTableSummaryQuery();
    $recorder->reset();

    $state = $host->getTableSummarySelectedState($query);

    expect($state)->toBe(['sy_summary_0' => 210, 'sy_summary_1' => 35.0, 'sy_summary_2' => 6])
        ->and($recorder->statements())->toHaveCount(1)
        ->and($recorder->statements()[0])->not->toContain('GROUP BY');
});

it('hands each batched summarizer the alias its own figure comes back under', function (): void {
    $sum = Sum::make();
    $average = Average::make();
    $host = summaryStateHost(summaryStateSongs(), [TextColumn::make('plays')->summarize([$sum, $average])], recordsPerPage: 'all');

    $state = $host->getTableSummarySelectedState($host->getAllTableSummaryQuery());

    $sum->selectedState($state);
    $average->selectedState($state);

    expect($sum->getSelectedState())->toBe(210)
        ->and($average->getSelectedState())->toBe(35.0);
});

/*
 * The ordinal advances once per summarizer the batch pushed an alias onto, never once
 * per select emitted: an icon count takes an alias and contributes no statement, so
 * counting the statements instead would push that same alias onto the next summarizer
 * and hand the icon count its neighbour's figure — as a figure of its own, in place of
 * the refusal it owes.
 */
it('advances the batch alias per summarizer pushed rather than per select emitted', function (): void {
    $icons = Count::make()->icons();
    $sum = Sum::make();
    $host = summaryStateHost(summaryStateSongs(), [TextColumn::make('plays')->summarize([$icons, $sum])], recordsPerPage: 'all');

    $state = $host->getTableSummarySelectedState($host->getAllTableSummaryQuery());

    $icons->selectedState($state);
    $sum->selectedState($state);

    expect($state)->toBe(['sy_summary_1' => 210])
        ->and($sum->getSelectedState())->toBe(210)
        ->and($icons->hasSelectedState())->toBeFalse()
        ->and(fn (): mixed => $icons->getState())->toThrow(LogicException::class, 'IconColumn');
});

it('leaves a scoped summarizer out of the batch so it runs its own query', function (): void {
    $scoped = Count::make()->query(fn (QueryBuilder $query): QueryBuilder => $query->andWhere('song.plays > 30'));
    $host = summaryStateHost(summaryStateSongs(), [
        TextColumn::make('plays')->summarize([Sum::make(), $scoped]),
    ], recordsPerPage: 'all');

    $query = $host->getAllTableSummaryQuery();

    expect($host->getTableSummarySelectedState($query))->toBe(['sy_summary_0' => 210])
        ->and($scoped->query($query)->getState())->toBe(3);
});

it('batches nothing when no summarizer emits a select statement', function (): void {
    $host = summaryStateHost(summaryStateSongs(), [
        TextColumn::make('plays')->summarize([Range::make(), Values::make()]),
    ], recordsPerPage: 'all');

    expect($host->getTableSummarySelectedState($host->getAllTableSummaryQuery()))->toBe([]);
});

it('reads a to-one relation column off one join it reuses', function (): void {
    $host = summaryStateHost(summaryStateSongs(), [
        TextColumn::make('artist.name')->summarize(Count::make()),
        TextColumn::make('artist.id')->summarize(Count::make()),
    ], recordsPerPage: 'all');

    $query = $host->getAllTableSummaryQuery();
    $state = $host->getTableSummarySelectedState($query);

    expect($state)->toBe(['sy_summary_0' => 6, 'sy_summary_1' => 6])
        ->and(mb_substr_count($query?->getDQL() ?? '', 'LEFT JOIN song.artist'))->toBe(1);
});

it('reads an embeddable path off the root alias', function (): void {
    $host = summaryStateHost(summaryStateSongs(), [
        TextColumn::make('artist.address.street')->summarize(Count::make()),
    ], recordsPerPage: 'all');

    expect($host->getTableSummarySelectedState($host->getAllTableSummaryQuery()))->toBe(['sy_summary_0' => 6]);
});

it('resolves no expression for a column whose summarizers all sit out the batch', function (): void {
    $host = summaryStateHost(summaryStateSongs(), [
        TextColumn::make('genres.name')->summarize(Values::make()),
        TextColumn::make('plays')->summarize(Sum::make()),
    ], recordsPerPage: 'all');

    expect($host->getTableSummarySelectedState($host->getAllTableSummaryQuery()))->toBe(['sy_summary_0' => 210]);
});

it('refuses a column summarized through a to-many association', function (): void {
    $host = summaryStateHost(summaryStateSongs(), [
        TextColumn::make('genres.name')->summarize(Count::make()),
    ], recordsPerPage: 'all');

    expect(fn (): array => $host->getTableSummarySelectedState($host->getAllTableSummaryQuery()))
        ->toThrow(LogicException::class, 'Cannot summarize [genres.name]');
});

/*
 * A to-many join multiplies the rows every aggregate on the statement reads, so a
 * user's own joining query is refused rather than answered with an inflated figure.
 */
it('refuses to summarize over a query that joins a to-many association', function (): void {
    $entityManager = summaryStateSongs();
    $host = new TableHost(fn (Table $table): Table => $table
        ->query($entityManager->createQueryBuilder()->select('song')->from(Song::class, 'song')->leftJoin('song.genres', 'sy_genres'))
        ->columns([TextColumn::make('plays')->summarize(Sum::make())]));

    expect(fn (): array => $host->getTableSummarySelectedState($host->getAllTableSummaryQuery()))
        ->toThrow(LogicException::class, 'Summarizer::using()');
});

it('leaves an aggregate column out of the batch, where its refusal is read time', function (): void {
    $entityManager = summaryStateSongs();

    EntityManagerFactory::seed($entityManager, new Genre(1, 'Rock'));

    $host = summaryStateHost($entityManager, [
        TextColumn::make('genres_count')->counts('genres')->summarize(Sum::make()),
        TextColumn::make('plays')->summarize(Sum::make()),
    ], recordsPerPage: 'all');

    expect($host->getTableSummarySelectedState($host->getAllTableSummaryQuery()))->toBe(['sy_summary_0' => 210]);
});

/*
 * The band renders the visible columns, so the batch reads them too — a toggled-off
 * column contributes no aggregate to a statement whose value nothing would read.
 */
it('batches the visible columns rather than every declared one', function (): void {
    $host = summaryStateHost(summaryStateSongs(), [
        TextColumn::make('plays')->summarize(Sum::make())->hidden(),
        TextColumn::make('id')->summarize(Count::make()),
    ], recordsPerPage: 'all');

    expect($host->getTableSummarySelectedState($host->getAllTableSummaryQuery()))->toBe(['sy_summary_0' => 6]);
});

it('hands back an independent builder on every read', function (): void {
    $host = summaryStateHost(summaryStateSongs(), [TextColumn::make('plays')->summarize(Sum::make())]);

    $first = $host->getAllTableSummaryQuery();
    $second = $host->getAllTableSummaryQuery();

    $first?->andWhere('song.plays > 30');

    expect($first)->not->toBe($second)
        ->and($second?->getDQL())->not->toContain('song.plays > 30')
        ->and($host->getTableSummarySelectedState($second))->toBe(['sy_summary_0' => 210]);
});

/**
 * The same six songs the ungrouped cases read, grouped by their artist and paged —
 * three per artist, so one group sums to 60 and the other to 150 against the whole
 * table's 210, and no page can be mistaken for a group or for the table.
 *
 * @param (Closure(Table): Table)|null $configure
 */
function groupedSummaryHost(EntityManager $entityManager, int|string|null $recordsPerPage = 2, int $page = 1, ?Closure $configure = null): TableHost
{
    $host = new TableHost(function (Table $table) use ($entityManager, $recordsPerPage, $configure): Table {
        $table = $table
            ->query($entityManager->createQueryBuilder()->select('song')->from(Song::class, 'song')->orderBy('song.id', 'ASC'))
            ->columns([TextColumn::make('plays')->summarize(Sum::make())])
            ->groups(['artist.name', 'title']);

        $table = $recordsPerPage === null ? $table->paginated(false) : $table->paginated([$recordsPerPage]);

        return $configure instanceof Closure ? $configure($table) : $table;
    });

    $host->tableGrouping = 'artist.name';
    $host->tablePage = $page;

    return $host;
}

it('summarizes each group in one statement of its own', function (): void {
    $recorder = new RecordingMiddleware();
    $host = groupedSummaryHost(summaryStateSongs($recorder), recordsPerPage: null);

    $records = $host->getTableRecords();
    $recorder->reset();

    $radiohead = $host->getTableSummarySelectedState($host->getGroupedTableSummaryQuery($records[1]));
    $bjork = $host->getTableSummarySelectedState($host->getGroupedTableSummaryQuery($records[4]));

    expect($radiohead)->toBe(['sy_summary_0' => 60])
        ->and($bjork)->toBe(['sy_summary_0' => 150])
        ->and($recorder->statements())->toHaveCount(2);
});

it('scopes a group holding a single record', function (): void {
    $host = groupedSummaryHost(summaryStateSongs(), recordsPerPage: null);
    $host->tableGrouping = 'title';

    $records = $host->getTableRecords();

    expect($host->getTableSummarySelectedState($host->getGroupedTableSummaryQuery($records[1])))->toBe(['sy_summary_0' => 10]);
});

it('honours the group scope closure on the grouped summary path', function (): void {
    $host = groupedSummaryHost(summaryStateSongs(), recordsPerPage: null, configure: fn (Table $table): Table => $table->groups([
        Group::make('artist.name')->scopeQueryUsing(fn (QueryBuilder $query): QueryBuilder => $query->andWhere('song.plays > 10')),
    ]));

    $records = $host->getTableRecords();

    expect($host->getTableSummarySelectedState($host->getGroupedTableSummaryQuery($records[1])))->toBe(['sy_summary_0' => 200]);
});

it('answers no grouped summary query when nothing is grouped or nothing is queried', function (): void {
    $ungrouped = groupedSummaryHost(summaryStateSongs(), recordsPerPage: null);
    $ungrouped->tableGrouping = null;

    $records = $ungrouped->getTableRecords();

    $recordsLane = new TableHost(fn (Table $table): Table => $table
        ->records(fn (): array => [['id' => '1', 'plays' => 10]])
        ->columns([TextColumn::make('plays')->summarize(Sum::make())])
        ->groups(['plays']));
    $recordsLane->tableGrouping = 'plays';

    expect($ungrouped->getGroupedTableSummaryQuery($records[1]))->toBeNull()
        ->and($recordsLane->getGroupedTableSummaryQuery(['id' => '1', 'plays' => 10]))->toBeNull();
});

/*
 * The scope lands on a builder of the group's own, so the all-table row that reads
 * the next one is not quietly narrowed to whichever group asked first.
 */
it('scopes a fresh clone rather than the query the all-table row reads', function (): void {
    $host = groupedSummaryHost(summaryStateSongs(), recordsPerPage: null);

    $records = $host->getTableRecords();
    $grouped = $host->getGroupedTableSummaryQuery($records[1]);

    expect($grouped)->not->toBe($host->getAllTableSummaryQuery())
        ->and($host->getTableSummarySelectedState($grouped))->toBe(['sy_summary_0' => 60])
        ->and($host->getTableSummarySelectedState($host->getAllTableSummaryQuery()))->toBe(['sy_summary_0' => 210]);
});

/*
 * A date group keys by the day, so its scope has to be the half-open range around
 * that day rather than an equality against an instant — two songs released hours
 * apart on one day summarize together.
 */
it('scopes a date group to the whole day its key names', function (): void {
    $entityManager = EntityManagerFactory::create();

    EntityManagerFactory::seed(
        $entityManager,
        new Song(id: 1, title: 'Airbag', plays: 10, releasedAt: new DateTimeImmutable('2026-09-02 09:00:00')),
        new Song(id: 2, title: 'Let Down', plays: 20, releasedAt: new DateTimeImmutable('2026-09-02 21:30:00')),
        new Song(id: 3, title: 'Jóga', plays: 30, releasedAt: new DateTimeImmutable('2026-09-03 08:00:00')),
    );

    $host = groupedSummaryHost($entityManager, recordsPerPage: null, configure: fn (Table $table): Table => $table->groups([
        Group::make('releasedAt')->date(),
    ]));
    $host->tableGrouping = 'releasedAt';

    $records = $host->getTableRecords();

    expect($host->getTableSummarySelectedState($host->getGroupedTableSummaryQuery($records[1])))->toBe(['sy_summary_0' => 30])
        ->and($host->getTableSummarySelectedState($host->getGroupedTableSummaryQuery($records[3])))->toBe(['sy_summary_0' => 30]);
});

it('renders no trailing grouped summary for a record that is not there', function (): void {
    $host = groupedSummaryHost(summaryStateSongs());

    expect($host->shouldRenderTrailingGroupedTableSummary(null))->toBeFalse();
});

it('renders the trailing grouped summary where no next page can continue the group', function (): void {
    $unpaginated = groupedSummaryHost(summaryStateSongs(), recordsPerPage: null);
    $lastPage = groupedSummaryHost(summaryStateSongs(), page: 3);
    $wholeTableOnOnePage = groupedSummaryHost(summaryStateSongs(), recordsPerPage: 'all');

    expect($unpaginated->shouldRenderTrailingGroupedTableSummary($unpaginated->getTableRecords()[6]))->toBeTrue()
        ->and($lastPage->shouldRenderTrailingGroupedTableSummary($lastPage->getTableRecords()->getRecords()[6]))->toBeTrue()
        ->and($wholeTableOnOnePage->shouldRenderTrailingGroupedTableSummary($wholeTableOnOnePage->getTableRecords()->getRecords()[6]))->toBeTrue();
});

it('renders the trailing grouped summary on a table that groups nothing', function (): void {
    $host = groupedSummaryHost(summaryStateSongs());
    $host->tableGrouping = null;

    expect($host->shouldRenderTrailingGroupedTableSummary($host->getTableRecords()->getRecords()[2]))->toBeTrue();
});

/*
 * The whole point of the probe: the first two songs are two thirds of one artist's
 * three, so summarizing that group here would put two records' figure under a
 * heading that names three.
 */
it('suppresses the trailing grouped summary when the next page continues the group', function (): void {
    $host = groupedSummaryHost(summaryStateSongs());

    expect($host->shouldRenderTrailingGroupedTableSummary($host->getTableRecords()->getRecords()[2]))->toBeFalse();
});

it('renders the trailing grouped summary when the next page starts a new group', function (): void {
    $host = groupedSummaryHost(summaryStateSongs(), recordsPerPage: 3);

    expect($host->shouldRenderTrailingGroupedTableSummary($host->getTableRecords()->getRecords()[3]))->toBeTrue();
});

/*
 * The paginator counted the rows before the look-ahead reads them, so a page that
 * claims a successor can still be followed by nothing at all.
 */
it('renders the trailing grouped summary when the next page turns out to be empty', function (): void {
    $entityManager = summaryStateSongs();
    $host = groupedSummaryHost($entityManager);

    $lastRecord = $host->getTableRecords()->getRecords()[2];

    $entityManager->createQuery('DELETE FROM ' . Song::class . ' song WHERE song.id > 2')->execute();

    expect($host->getTablePaginator()?->hasMorePages())->toBeTrue()
        ->and($host->shouldRenderTrailingGroupedTableSummary($lastRecord))->toBeTrue();
});

/*
 * A `date()` group keys by the day, so the look-ahead compares days too: two songs
 * released hours apart across a page break are still one group, and one released the
 * next morning is not.
 */
it('compares the days of a date group across the page break', function (): void {
    $entityManager = EntityManagerFactory::create();

    EntityManagerFactory::seed(
        $entityManager,
        new Song(id: 1, title: 'Airbag', plays: 10, releasedAt: new DateTimeImmutable('2026-09-02 09:00:00')),
        new Song(id: 2, title: 'Let Down', plays: 20, releasedAt: new DateTimeImmutable('2026-09-02 21:30:00')),
        new Song(id: 3, title: 'No Surprises', plays: 30, releasedAt: new DateTimeImmutable('2026-09-02 23:45:00')),
        new Song(id: 4, title: 'Jóga', plays: 40, releasedAt: new DateTimeImmutable('2026-09-03 01:15:00')),
    );

    $dated = fn (int $recordsPerPage): TableHost => groupedSummaryHost(
        $entityManager,
        recordsPerPage: $recordsPerPage,
        configure: fn (Table $table): Table => $table->groups([Group::make('releasedAt')->date()]),
    );

    $sameDay = $dated(2);
    $sameDay->tableGrouping = 'releasedAt';

    $nextDay = $dated(3);
    $nextDay->tableGrouping = 'releasedAt';

    expect($sameDay->shouldRenderTrailingGroupedTableSummary($sameDay->getTableRecords()->getRecords()[2]))->toBeFalse()
        ->and($nextDay->shouldRenderTrailingGroupedTableSummary($nextDay->getTableRecords()->getRecords()[3]))->toBeTrue();
});

it('costs one statement to look ahead, and none where an early return answers', function (): void {
    $recorder = new RecordingMiddleware();
    $entityManager = summaryStateSongs($recorder);

    $host = groupedSummaryHost($entityManager);
    $lastRecord = $host->getTableRecords()->getRecords()[2];

    $recorder->reset();

    expect($host->shouldRenderTrailingGroupedTableSummary($lastRecord))->toBeFalse()
        ->and($recorder->statements())->toHaveCount(1);

    $lastPage = groupedSummaryHost($entityManager, page: 3);
    $lastPageRecord = $lastPage->getTableRecords()->getRecords()[6];

    $recorder->reset();

    expect($lastPage->shouldRenderTrailingGroupedTableSummary($lastPageRecord))->toBeTrue()
        ->and($recorder->statements())->toBe([]);
});

/*
 * A to-many fetch join makes rows and records different things, so the naive window
 * of one hands back a row of the record the page already ended on — the same trap
 * `paginateTableQuery()` routes around, and the same way out.
 */
it('reads past a fetch-joined to-many through the paginator', function (): void {
    $entityManager = EntityManagerFactory::create();

    $radiohead = new Artist(1, 'Radiohead');
    $portishead = new Artist(2, 'Portishead');
    $rock = new Genre(1, 'Rock');
    $tripHop = new Genre(2, 'Trip hop');
    $airbag = new Song(id: 1, title: 'Airbag', plays: 10, artist: $radiohead);

    // Two genres on the page's first song, so the fetch join makes the window of
    // one land inside that song rather than on the record after the page.
    $airbag->getGenres()->add($rock);
    $airbag->getGenres()->add($tripHop);

    EntityManagerFactory::seed(
        $entityManager,
        $radiohead,
        $portishead,
        $rock,
        $tripHop,
        $airbag,
        new Song(id: 2, title: 'Let Down', plays: 20, artist: $radiohead),
        new Song(id: 3, title: 'Glory Box', plays: 30, artist: $portishead),
        new Song(id: 4, title: 'Roads', plays: 40, artist: $portishead),
    );

    $host = new TableHost(fn (Table $table): Table => $table
        ->query($entityManager->createQueryBuilder()
            ->select('song', 'sy_genres')
            ->from(Song::class, 'song')
            ->leftJoin('song.genres', 'sy_genres')
            ->orderBy('song.id', 'ASC'))
        ->columns([TextColumn::make('plays')->summarize(Sum::make())])
        ->groups(['artist.name'])
        ->paginated([2]));
    $host->tableGrouping = 'artist.name';

    expect($host->shouldRenderTrailingGroupedTableSummary($host->getTableRecords()->getRecords()[2]))->toBeTrue();
});
