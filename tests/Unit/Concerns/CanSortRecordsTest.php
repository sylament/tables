<?php

declare(strict_types=1);

use Doctrine\ORM\EntityManager;
use Doctrine\ORM\QueryBuilder;
use Sylament\Tables\Columns\TextColumn;
use Sylament\Tables\Table;
use Sylament\Tables\Tests\Fixtures\Concerns\TableHost;
use Sylament\Tables\Tests\Fixtures\Doctrine\Entity\Book;
use Sylament\Tables\Tests\Fixtures\Doctrine\Entity\CompositePair;
use Sylament\Tables\Tests\Fixtures\Doctrine\Entity\Song;
use Sylament\Tables\Tests\Fixtures\Doctrine\EntityManagerFactory;

/*
 * Covers the query half of host-side sorting — the `ORDER BY` the lane composes.
 * The `sortTable()` state cycle it reads stays in `HasTableTest`.
 */

function seededSongs(): EntityManager
{
    $entityManager = EntityManagerFactory::create();

    EntityManagerFactory::seed(
        $entityManager,
        new Song(1, 'Charlie', 30),
        new Song(2, 'Alpha', 10),
        new Song(3, 'Bravo', 20),
    );

    return $entityManager;
}

/**
 * @return list<string>
 */
function sortedSongTitles(TableHost $host): array
{
    $titles = [];

    foreach ($host->getTableRecords() as $record) {
        $titles[] = $record->getTitle();
    }

    return $titles;
}

it('orders by the user sort with the identifier tiebreaker appended', function (): void {
    $entityManager = seededSongs();
    $host = new TableHost(fn (Table $table): Table => $table
        ->query($entityManager->createQueryBuilder()->select('song')->from(Song::class, 'song'))
        ->columns([TextColumn::make('title')->sortable()]));

    $host->sortTable('title');

    expect(sortedSongTitles($host))->toBe(['Alpha', 'Bravo', 'Charlie'])
        ->and(array_keys($host->getTableRecords()->getRecords()))->toBe([2, 3, 1])
        ->and($host->getFilteredSortedTableQuery()?->getDQL())->toContain('ORDER BY song.title asc, song.id asc');
});

it('applies the default sort when the user has not sorted', function (): void {
    $entityManager = seededSongs();
    $host = new TableHost(fn (Table $table): Table => $table
        ->query($entityManager->createQueryBuilder()->select('song')->from(Song::class, 'song'))
        ->columns([TextColumn::make('title')->sortable(), TextColumn::make('plays')->sortable()])
        ->defaultSort('plays', 'desc'));

    expect(sortedSongTitles($host))->toBe(['Charlie', 'Bravo', 'Alpha'])
        ->and($host->getFilteredSortedTableQuery()?->getDQL())->toContain('ORDER BY song.plays desc, song.id desc');
});

it('lowercases a mixed-case default sort direction', function (): void {
    $entityManager = seededSongs();
    $host = new TableHost(fn (Table $table): Table => $table
        ->query($entityManager->createQueryBuilder()->select('song')->from(Song::class, 'song'))
        ->columns([TextColumn::make('title')->sortable(), TextColumn::make('plays')->sortable()])
        ->defaultSort('plays', 'DESC'));

    expect($host->getTable()->getDefaultSortDirection())->toBe('desc')
        ->and($host->getFilteredSortedTableQuery()?->getDQL())->toContain('ORDER BY song.plays desc, song.id desc')
        ->and(sortedSongTitles($host))->toBe(['Charlie', 'Bravo', 'Alpha']);
});

it('appends the default sort as a secondary order under a user sort', function (): void {
    $entityManager = seededSongs();
    $host = new TableHost(fn (Table $table): Table => $table
        ->query($entityManager->createQueryBuilder()->select('song')->from(Song::class, 'song'))
        ->columns([TextColumn::make('title')->sortable(), TextColumn::make('plays')->sortable()])
        ->defaultSort('plays'));

    $host->sortTable('title');

    expect($host->getFilteredSortedTableQuery()?->getDQL())->toContain('ORDER BY song.title asc, song.plays asc, song.id asc')
        ->and(sortedSongTitles($host))->toBe(['Alpha', 'Bravo', 'Charlie']);
});

it('orders everything after the user sort in the declared default direction', function (): void {
    $entityManager = seededSongs();
    $host = new TableHost(fn (Table $table): Table => $table
        ->query($entityManager->createQueryBuilder()->select('song')->from(Song::class, 'song'))
        ->columns([TextColumn::make('title')->sortable(), TextColumn::make('plays')->sortable()])
        ->defaultSort('plays'));

    $host->sortTable('title', 'desc');

    expect($host->getFilteredSortedTableQuery()?->getDQL())->toContain('ORDER BY song.title desc, song.plays asc, song.id asc')
        ->and(sortedSongTitles($host))->toBe(['Charlie', 'Bravo', 'Alpha']);
});

it('does not double-apply a default sort naming the sorted column', function (): void {
    $entityManager = seededSongs();
    $host = new TableHost(fn (Table $table): Table => $table
        ->query($entityManager->createQueryBuilder()->select('song')->from(Song::class, 'song'))
        ->columns([TextColumn::make('title')->sortable()])
        ->defaultSort('title'));

    $host->sortTable('title');

    $dql = $host->getFilteredSortedTableQuery()?->getDQL() ?? '';

    expect($dql)->toContain('ORDER BY song.title asc, song.id asc')
        ->and(mb_substr_count($dql, 'song.title'))->toBe(1)
        ->and(sortedSongTitles($host))->toBe(['Alpha', 'Bravo', 'Charlie']);
});

it('resolves a default sort that is not a column through the path resolver', function (): void {
    $entityManager = seededSongs();
    $host = new TableHost(fn (Table $table): Table => $table
        ->query($entityManager->createQueryBuilder()->select('song')->from(Song::class, 'song'))
        ->columns([TextColumn::make('title')->sortable()])
        ->defaultSort('plays'));

    expect($host->getFilteredSortedTableQuery()?->getDQL())->toContain('ORDER BY song.plays asc, song.id asc')
        ->and(sortedSongTitles($host))->toBe(['Alpha', 'Bravo', 'Charlie']);
});

it('lets a default sort closure order the query itself', function (): void {
    $entityManager = seededSongs();
    $host = new TableHost(fn (Table $table): Table => $table
        ->query($entityManager->createQueryBuilder()->select('song')->from(Song::class, 'song'))
        ->columns([TextColumn::make('title')->sortable()])
        ->defaultSort(function (QueryBuilder $query, string $direction): void {
            $query->addOrderBy('song.plays', $direction);
        }, 'desc'));

    expect($host->getFilteredSortedTableQuery()?->getDQL())->toContain('ORDER BY song.plays desc, song.id desc')
        ->and(sortedSongTitles($host))->toBe(['Charlie', 'Bravo', 'Alpha']);
});

it('lets a default sort closure replace the query', function (): void {
    $entityManager = seededSongs();
    $host = new TableHost(fn (Table $table): Table => $table
        ->query($entityManager->createQueryBuilder()->select('song')->from(Song::class, 'song'))
        ->columns([TextColumn::make('title')->sortable()])
        ->defaultSort(fn (): QueryBuilder => $entityManager->createQueryBuilder()
            ->select('song')
            ->from(Song::class, 'song')
            ->andWhere('song.plays > 15')));

    $records = $host->getTableRecords()->getRecords();

    expect($records)->toHaveCount(2)
        ->and(array_keys($records))->toBe([1, 3])
        ->and($host->getFilteredSortedTableQuery()?->getDQL())->toContain('WHERE song.plays > 15 ORDER BY song.id asc');
});

it('drops the user sort when a default sort closure replaces the query', function (): void {
    $entityManager = seededSongs();
    $host = new TableHost(fn (Table $table): Table => $table
        ->query($entityManager->createQueryBuilder()->select('song')->from(Song::class, 'song'))
        ->columns([TextColumn::make('title')->sortable()])
        ->defaultSort(fn (): QueryBuilder => $entityManager->createQueryBuilder()
            ->select('song')
            ->from(Song::class, 'song')
            ->andWhere('song.plays > 15')));

    $host->sortTable('title');

    $dql = $host->getFilteredSortedTableQuery()?->getDQL() ?? '';

    expect($dql)->toContain('WHERE song.plays > 15 ORDER BY song.id asc')
        ->and($dql)->not->toContain('song.title')
        ->and(array_keys($host->getTableRecords()->getRecords()))->toBe([1, 3]);
});

it('reads the tiebreaker off the builder a default sort closure returned', function (): void {
    $entityManager = seededSongs();
    $host = new TableHost(fn (Table $table): Table => $table
        ->query($entityManager->createQueryBuilder()->select('song')->from(Song::class, 'song'))
        ->columns([TextColumn::make('title')->sortable()])
        ->defaultSort(fn (): QueryBuilder => $entityManager->createQueryBuilder()
            ->select('book')
            ->from(Book::class, 'book')));

    $query = $host->getFilteredSortedTableQuery();

    expect($query?->getDQL())->toContain('ORDER BY book.code asc')
        ->and($query?->getDQL())->not->toContain('song.')
        ->and($query?->getQuery()->getSQL())->toContain('ORDER BY');
});

it('takes the default sort column and the key sort switch from closures', function (): void {
    $entityManager = seededSongs();
    $host = new TableHost(fn (Table $table): Table => $table
        ->query($entityManager->createQueryBuilder()->select('song')->from(Song::class, 'song'))
        ->columns([TextColumn::make('title')->sortable()])
        ->defaultSort(fn (): string => 'plays', 'desc')
        ->defaultKeySort(fn (): bool => false));

    $dql = $host->getFilteredSortedTableQuery()?->getDQL() ?? '';

    expect($dql)->toContain('ORDER BY song.plays desc')
        ->and($dql)->not->toContain('song.id')
        ->and(sortedSongTitles($host))->toBe(['Charlie', 'Bravo', 'Alpha']);
});

it('suppresses the tiebreaker when the key is already ordered', function (): void {
    $entityManager = seededSongs();
    $keyed = new TableHost(fn (Table $table): Table => $table
        ->query($entityManager->createQueryBuilder()
            ->select('song')
            ->from(Song::class, 'song')
            ->addOrderBy('song.id', 'DESC')));
    $joined = new TableHost(fn (Table $table): Table => $table
        ->query($entityManager->createQueryBuilder()
            ->select('song')
            ->from(Song::class, 'song')
            ->leftJoin('song.artist', 'artist')
            ->addOrderBy('artist.id', 'ASC')));

    $keyedDql = $keyed->getFilteredSortedTableQuery()?->getDQL() ?? '';

    expect($keyedDql)->toContain('ORDER BY song.id DESC')
        ->and(mb_substr_count($keyedDql, 'song.id'))->toBe(1)
        ->and(sortedSongTitles($keyed))->toBe(['Bravo', 'Alpha', 'Charlie'])
        ->and($joined->getFilteredSortedTableQuery()?->getDQL())->toContain('ORDER BY artist.id ASC')
        ->and($joined->getFilteredSortedTableQuery()?->getDQL())->not->toContain('song.id');
});

it('adds no tiebreaker for a composite identifier', function (): void {
    $entityManager = EntityManagerFactory::create();
    $host = new TableHost(fn (Table $table): Table => $table
        ->query($entityManager->createQueryBuilder()->select('pair')->from(CompositePair::class, 'pair')));

    $dql = $host->getFilteredSortedTableQuery()?->getDQL() ?? '';

    expect($dql)->toContain('FROM ' . CompositePair::class . ' pair')
        ->and($dql)->not->toContain('ORDER BY');
});

it('omits the tiebreaker when defaultKeySort is off', function (): void {
    $entityManager = seededSongs();
    $host = new TableHost(fn (Table $table): Table => $table
        ->query($entityManager->createQueryBuilder()->select('song')->from(Song::class, 'song'))
        ->columns([TextColumn::make('title')->sortable()])
        ->defaultKeySort(false));

    $host->sortTable('title');

    expect($host->getFilteredSortedTableQuery()?->getDQL())->toContain('ORDER BY song.title asc')
        ->and($host->getFilteredSortedTableQuery()?->getDQL())->not->toContain('song.id');
});

it('keeps a user-authored order ahead of table sorts', function (): void {
    $entityManager = seededSongs();
    $host = new TableHost(fn (Table $table): Table => $table
        ->query($entityManager->createQueryBuilder()
            ->select('song')
            ->from(Song::class, 'song')
            ->addOrderBy('song.plays', 'ASC'))
        ->columns([TextColumn::make('title')->sortable()]));

    $host->sortTable('title');

    expect($host->getFilteredSortedTableQuery()?->getDQL())->toContain('ORDER BY song.plays ASC, song.title asc, song.id asc')
        ->and(sortedSongTitles($host))->toBe(['Alpha', 'Bravo', 'Charlie']);
});
