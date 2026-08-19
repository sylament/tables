<?php

declare(strict_types=1);

use Doctrine\ORM\EntityManager;
use Doctrine\ORM\QueryBuilder;
use Sylament\Support\Pagination\Paginator;
use Sylament\Tables\Columns\Column;
use Sylament\Tables\Columns\TextColumn;
use Sylament\Tables\Table;
use Sylament\Tables\Tests\Fixtures\Concerns\TableHost;
use Sylament\Tables\Tests\Fixtures\Doctrine\Entity\Artist;
use Sylament\Tables\Tests\Fixtures\Doctrine\Entity\Gig;
use Sylament\Tables\Tests\Fixtures\Doctrine\Entity\Song;
use Sylament\Tables\Tests\Fixtures\Doctrine\Entity\Venue;
use Sylament\Tables\Tests\Fixtures\Doctrine\EntityManagerFactory;
use Sylament\Tables\Tests\Fixtures\Doctrine\Handle;
use Sylament\Tables\Tests\Fixtures\Doctrine\RecordingMiddleware;

/**
 * Radiohead with two songs, Adele with none, Björk with one — paged two at a
 * time, so the first page carries both a record the batch answers for and one
 * it leaves absent.
 */
function artistsWithSongs(?RecordingMiddleware $recorder = null): EntityManager
{
    $entityManager = EntityManagerFactory::create($recorder);

    $radiohead = new Artist(1, 'Radiohead');
    $adele = new Artist(2, 'Adele');
    $bjork = new Artist(3, 'Björk');

    EntityManagerFactory::seed(
        $entityManager,
        $radiohead,
        $adele,
        $bjork,
        new Song(1, 'Airbag', 10, $radiohead),
        new Song(2, 'Let Down', 20, $radiohead),
        new Song(3, 'Jóga', 300, $bjork),
    );

    return $entityManager;
}

/**
 * @param array<Column> $columns
 */
function artistTableHost(EntityManager $entityManager, array $columns, int|string $recordsPerPage = 2): TableHost
{
    return new TableHost(function (Table $table) use ($entityManager, $columns, $recordsPerPage): Table {
        $table = $table
            ->query($entityManager->createQueryBuilder()->select('artist')->from(Artist::class, 'artist')->orderBy('artist.id', 'ASC'))
            ->columns($columns);

        return $recordsPerPage === 'all'
            ? $table->paginated([10, 'all'])->defaultPaginationPageOption('all')
            : $table->paginated([$recordsPerPage]);
    });
}

/**
 * Four artists whose song counts run against their id order — Adele 1,
 * Radiohead 3, Sade 0, Björk 2 — so an id-ordered page and a count-ordered one
 * can never be mistaken for each other.
 */
function artistsBySongCount(?RecordingMiddleware $recorder = null): EntityManager
{
    $entityManager = EntityManagerFactory::create($recorder);

    $adele = new Artist(1, 'Adele');
    $radiohead = new Artist(2, 'Radiohead');
    $sade = new Artist(3, 'Sade');
    $bjork = new Artist(4, 'Björk');

    EntityManagerFactory::seed(
        $entityManager,
        $adele,
        $radiohead,
        $sade,
        $bjork,
        new Song(1, 'Hello', 10, $adele),
        new Song(2, 'Airbag', 20, $radiohead),
        new Song(3, 'Let Down', 30, $radiohead),
        new Song(4, 'No Surprises', 40, $radiohead),
        new Song(5, 'Jóga', 500, $bjork),
        new Song(6, 'Hyperballad', 600, $bjork),
    );

    return $entityManager;
}

/**
 * A host over the unordered artist query, so the sort under test is the only
 * ordering the page has beyond the identifier tiebreak the host appends.
 *
 * @param array<Column> $columns
 */
function sortedArtistTableHost(EntityManager $entityManager, array $columns, string $sort, int $page = 1, int $recordsPerPage = 2): TableHost
{
    $host = new TableHost(fn (Table $table): Table => $table
        ->query($entityManager->createQueryBuilder()->select('artist')->from(Artist::class, 'artist'))
        ->columns($columns)
        ->paginated([$recordsPerPage]));

    $host->tableSort = $sort;
    $host->tablePage = $page;

    return $host;
}

/**
 * The named column's cell state on every row of the resolved page.
 *
 * @return list<mixed>
 */
function aggregateCellStates(TableHost $host, string $columnName): array
{
    $records = $host->getTableRecords();
    $column = $host->getTable()->getColumn($columnName);
    $states = [];

    foreach ($records instanceof Paginator ? $records->getRecords() : $records as $recordKey => $record) {
        $states[] = $column?->record($record)->recordKey($recordKey)->getState();
    }

    return $states;
}

it('fills the store for the page and the cell reads it', function (): void {
    $host = artistTableHost(artistsWithSongs(), [TextColumn::make('songs_count')->counts('songs')]);

    expect(aggregateCellStates($host, 'songs_count'))->toBe([2, 0]);
});

it('exists reads as a real bool with false for absent keys', function (): void {
    $host = artistTableHost(artistsWithSongs(), [TextColumn::make('songs_exists')->exists('songs')]);

    expect(aggregateCellStates($host, 'songs_exists'))->toBe([true, false]);
});

it('avg reads null for a record with no related rows', function (): void {
    $host = artistTableHost(artistsWithSongs(), [TextColumn::make('songs_avg_plays')->avg('songs', 'plays')]);

    expect(aggregateCellStates($host, 'songs_avg_plays'))->toBe([15.0, null]);
});

it('adds no aggregate artifacts to the page or count queries', function (): void {
    $recorder = new RecordingMiddleware();
    $host = artistTableHost(artistsWithSongs($recorder), [TextColumn::make('songs_count')->counts('songs')]);

    $recorder->reset();

    $states = aggregateCellStates($host, 'songs_count');
    $statements = $recorder->statements();

    expect($states)->toBe([2, 0])
        ->and($statements)->toHaveCount(3)
        ->and($statements[0])->toContain('LIMIT')
        ->and($statements[0])->not->toContain('GROUP BY')
        ->and($statements[0])->not->toContain('COUNT(')
        ->and($statements[1])->toContain('GROUP BY')
        ->and($statements[2])->toContain('COUNT(')
        ->and($statements[2])->not->toContain('GROUP BY');
});

it('binds a declared scope closure through the column evaluator', function (): void {
    $scope = static fn (string $alias, QueryBuilder $query): QueryBuilder => $query->andWhere("{$alias}.plays > 100");
    $host = artistTableHost(artistsWithSongs(), [TextColumn::make('songs_count')->counts(['songs' => $scope])], recordsPerPage: 'all');

    expect(aggregateCellStates($host, 'songs_count'))->toBe([0, 0, 1]);
});

it('leaves an inert declaration to the record walk', function (): void {
    $recorder = new RecordingMiddleware();
    $host = artistTableHost(artistsWithSongs($recorder), [TextColumn::make('name')->counts(['songs as songs_count'])]);
    $column = $host->getTable()->getColumn('name');

    $recorder->reset();

    expect($column?->hasRelationshipAggregates())->toBeTrue()
        ->and($column?->getCellAggregateDeclaration())->toBeNull()
        ->and(aggregateCellStates($host, 'name'))->toBe(['Radiohead', 'Adele'])
        ->and($recorder->statements())->toHaveCount(2);
});

it('fills a table whose records carry an object identifier', function (): void {
    $entityManager = EntityManagerFactory::create();
    $arena = new Venue(new Handle('arena'), 'The Arena');
    $shed = new Venue(new Handle('shed'), 'The Shed');

    EntityManagerFactory::seed(
        $entityManager,
        $arena,
        $shed,
        new Gig(1, 'Opening', 400, $arena),
        new Gig(2, 'Encore', 900, $arena),
    );

    $host = new TableHost(fn (Table $table): Table => $table
        ->query($entityManager->createQueryBuilder()->select('venue')->from(Venue::class, 'venue')->orderBy('venue.handle', 'ASC'))
        ->columns([TextColumn::make('gigs_count')->counts('gigs')])
        ->paginated(false));

    expect(aggregateCellStates($host, 'gigs_count'))->toBe([2, 0]);
});

it("fills on the 'all' per-page arm too", function (): void {
    $host = artistTableHost(artistsWithSongs(), [TextColumn::make('songs_count')->counts('songs')], recordsPerPage: 'all');

    expect(aggregateCellStates($host, 'songs_count'))->toBe([2, 0, 1]);
});

it('sorts by a counts column across a page boundary', function (): void {
    $entityManager = artistsBySongCount();
    $columns = static fn (): array => [
        TextColumn::make('name'),
        TextColumn::make('songs_count')->counts('songs')->sortable(),
    ];

    $firstPage = sortedArtistTableHost($entityManager, $columns(), 'songs_count:desc');
    $secondPage = sortedArtistTableHost($entityManager, $columns(), 'songs_count:desc', page: 2);

    expect(aggregateCellStates($firstPage, 'name'))->toBe(['Radiohead', 'Björk'])
        ->and(aggregateCellStates($firstPage, 'songs_count'))->toBe([3, 2])
        ->and(aggregateCellStates($secondPage, 'name'))->toBe(['Adele', 'Sade'])
        ->and(aggregateCellStates($secondPage, 'songs_count'))->toBe([1, 0])
        ->and($firstPage->getAllTableRecordsCount())->toBe(4)
        ->and($secondPage->getAllTableRecordsCount())->toBe(4);
});

it('sorts ascending with the identifier tiebreak intact', function (): void {
    $entityManager = artistsBySongCount();
    $columns = static fn (): array => [
        TextColumn::make('name'),
        TextColumn::make('songs_count')->counts('songs')->sortable(),
    ];

    $firstPage = sortedArtistTableHost($entityManager, $columns(), 'songs_count:asc');
    $secondPage = sortedArtistTableHost($entityManager, $columns(), 'songs_count:asc', page: 2);
    $dql = $firstPage->getFilteredSortedTableQuery()?->getDQL();

    expect(aggregateCellStates($firstPage, 'name'))->toBe(['Sade', 'Adele'])
        ->and(aggregateCellStates($secondPage, 'name'))->toBe(['Björk', 'Radiohead'])
        ->and(aggregateCellStates($secondPage, 'songs_count'))->toBe([2, 3])
        ->and($dql)->toContain('AS HIDDEN sy_agg1')
        ->and($dql)->toContain('ORDER BY sy_agg1 asc, artist.id asc');
});

it('sorts a fetch-joined collection query through the limit-subquery walker', function (): void {
    $entityManager = artistsBySongCount();
    $host = static function (int $page) use ($entityManager): TableHost {
        $host = new TableHost(fn (Table $table): Table => $table
            ->query($entityManager->createQueryBuilder()
                ->select('artist')
                ->addSelect('song')
                ->from(Artist::class, 'artist')
                ->leftJoin('artist.songs', 'song'))
            ->columns([TextColumn::make('name'), TextColumn::make('songs_count')->counts('songs')->sortable()])
            ->paginated([2]));

        $host->tableSort = 'songs_count:desc';
        $host->tablePage = $page;

        return $host;
    };

    $firstPage = $host(1);
    $secondPage = $host(2);

    expect(aggregateCellStates($firstPage, 'name'))->toBe(['Radiohead', 'Björk'])
        ->and(aggregateCellStates($firstPage, 'songs_count'))->toBe([3, 2])
        ->and(aggregateCellStates($secondPage, 'name'))->toBe(['Adele', 'Sade'])
        ->and($firstPage->getAllTableRecordsCount())->toBe(4);
});

it('keeps the count query free of the hidden select', function (): void {
    $recorder = new RecordingMiddleware();
    $host = sortedArtistTableHost(
        artistsBySongCount($recorder),
        [TextColumn::make('songs_count')->counts('songs')->sortable()],
        'songs_count:desc',
    );

    $recorder->reset();

    $total = $host->getAllTableRecordsCount();
    $statements = $recorder->statements();

    expect($total)->toBe(4)
        ->and($host->getFilteredSortedTableQuery()?->getDQL())->toContain('AS HIDDEN')
        ->and($host->getFilteredTableQuery()?->getDQL())->not->toContain('HIDDEN')
        ->and($statements)->toHaveCount(1)
        ->and($statements[0])->toContain('COUNT(')
        ->and($statements[0])->not->toContain('Song')
        ->and($statements[0])->not->toContain('ORDER BY');
});

it('carries a scoped aggregate sort and its parameters onto the page query', function (): void {
    $entityManager = artistsBySongCount();
    $scope = static fn (string $alias, QueryBuilder $query): QueryBuilder => $query
        ->andWhere("{$alias}.plays > :sylament_test_plays")
        ->setParameter('sylament_test_plays', 100);
    $columns = static fn (): array => [
        TextColumn::make('name'),
        TextColumn::make('songs_count')->counts(['songs' => $scope])->sortable(),
    ];

    $firstPage = sortedArtistTableHost($entityManager, $columns(), 'songs_count:desc');
    $secondPage = sortedArtistTableHost($entityManager, $columns(), 'songs_count:desc', page: 2);

    expect(aggregateCellStates($firstPage, 'name'))->toBe(['Björk', 'Sade'])
        ->and(aggregateCellStates($firstPage, 'songs_count'))->toBe([2, 0])
        ->and(aggregateCellStates($secondPage, 'name'))->toBe(['Radiohead', 'Adele'])
        ->and(aggregateCellStates($secondPage, 'songs_count'))->toBe([0, 0]);
});

it('keeps the correlation when a scope replaces the where clause', function (): void {
    $entityManager = artistsBySongCount();
    $scope = static fn (string $alias, QueryBuilder $query): QueryBuilder => $query->where("{$alias}.plays > 15");
    $columns = static fn (): array => [
        TextColumn::make('name'),
        TextColumn::make('songs_count')->counts(['songs' => $scope])->sortable(),
    ];

    $firstPage = sortedArtistTableHost($entityManager, $columns(), 'songs_count:desc');
    $secondPage = sortedArtistTableHost($entityManager, $columns(), 'songs_count:desc', page: 2);

    expect(aggregateCellStates($firstPage, 'name'))->toBe(['Radiohead', 'Björk'])
        ->and(aggregateCellStates($firstPage, 'songs_count'))->toBe([3, 2])
        ->and(aggregateCellStates($secondPage, 'name'))->toBe(['Sade', 'Adele'])
        ->and(aggregateCellStates($secondPage, 'songs_count'))->toBe([0, 0]);
});

it('honours an explicit sort-column list over the aggregate', function (): void {
    $host = sortedArtistTableHost(
        artistsBySongCount(),
        [TextColumn::make('name'), TextColumn::make('songs_count')->counts('songs')->sortable(['name'])],
        'songs_count:asc',
    );
    $dql = $host->getFilteredSortedTableQuery()?->getDQL();

    expect(aggregateCellStates($host, 'name'))->toBe(['Adele', 'Björk'])
        ->and(aggregateCellStates($host, 'songs_count'))->toBe([1, 2])
        ->and($dql)->toContain('ORDER BY artist.name asc')
        ->and($dql)->not->toContain('HIDDEN');
});

it('a searched, aggregate-sorted, paginated table stays consistent', function (): void {
    $entityManager = artistsBySongCount();
    $columns = static fn (): array => [
        TextColumn::make('name')->searchable(),
        TextColumn::make('songs_count')->counts('songs')->sortable(),
    ];

    $firstPage = sortedArtistTableHost($entityManager, $columns(), 'songs_count:desc');
    $firstPage->tableSearch = 'a';

    $secondPage = sortedArtistTableHost($entityManager, $columns(), 'songs_count:desc', page: 2);
    $secondPage->tableSearch = 'a';

    expect(aggregateCellStates($firstPage, 'name'))->toBe(['Radiohead', 'Adele'])
        ->and(aggregateCellStates($firstPage, 'songs_count'))->toBe([3, 1])
        ->and(aggregateCellStates($secondPage, 'name'))->toBe(['Sade'])
        ->and(aggregateCellStates($secondPage, 'songs_count'))->toBe([0])
        ->and($firstPage->getAllTableRecordsCount())->toBe(3);
});
