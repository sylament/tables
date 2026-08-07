<?php

declare(strict_types=1);

use Doctrine\ORM\EntityManager;
use Doctrine\ORM\QueryBuilder;
use Sylament\Tables\Columns\TextColumn;
use Sylament\Tables\Table;
use Sylament\Tables\Tests\Fixtures\Concerns\TableHost;
use Sylament\Tables\Tests\Fixtures\Doctrine\Entity\Song;
use Sylament\Tables\Tests\Fixtures\Doctrine\EntityManagerFactory;

/*
 * Covers the query half of host-side search — the WHERE the lane composes. The
 * `$tableSearch` state it reads stays in `HasTableTest`. SQLite matches `LIKE`
 * case-insensitively for ASCII, so the case arms are asserted as DQL and bound
 * parameters rather than as fetched rows.
 */

function searchableSongs(): EntityManager
{
    $entityManager = EntityManagerFactory::create();

    EntityManagerFactory::seed(
        $entityManager,
        new Song(1, 'Love Song', 10),
        new Song(2, 'Silent Love', 20),
        new Song(3, 'Noise', 30),
    );

    return $entityManager;
}

it('matches a single word across searchable columns', function (): void {
    $entityManager = searchableSongs();
    $configure = fn (Table $table): Table => $table
        ->query($entityManager->createQueryBuilder()->select('song')->from(Song::class, 'song'))
        ->columns([TextColumn::make('title')->searchable(), TextColumn::make('plays')->searchable()]);
    $titleMatch = new TableHost($configure);
    $playsMatch = new TableHost($configure);

    $titleMatch->tableSearch = 'love';
    $playsMatch->tableSearch = '30';

    expect(array_keys($titleMatch->getTableRecords()->getRecords()))->toBe([1, 2])
        ->and(array_keys($playsMatch->getTableRecords()->getRecords()))->toBe([3])
        ->and($titleMatch->getFilteredTableQuery()?->getDQL())
        ->toContain('WHERE song.title LIKE :sylament_search_0 OR song.plays LIKE :sylament_search_1');
});

it('composes split words with AND between groups', function (): void {
    $entityManager = searchableSongs();
    $configure = fn (Table $table): Table => $table
        ->query($entityManager->createQueryBuilder()->select('song')->from(Song::class, 'song'))
        ->columns([TextColumn::make('title')->searchable(), TextColumn::make('plays')->searchable()]);
    $bothWords = new TableHost($configure);
    $noOverlap = new TableHost($configure);

    $bothWords->tableSearch = 'silent love';
    $noOverlap->tableSearch = 'love noise';

    expect(array_keys($bothWords->getTableRecords()->getRecords()))->toBe([2])
        ->and($noOverlap->getTableRecords()->getRecords())->toBe([])
        ->and($bothWords->getFilteredTableQuery()?->getDQL())
        ->toContain('WHERE (song.title LIKE :sylament_search_0 OR song.plays LIKE :sylament_search_1) AND (song.title LIKE :sylament_search_2 OR song.plays LIKE :sylament_search_3)');
});

it('treats the term as one phrase when splitting is off', function (): void {
    $entityManager = searchableSongs();
    $host = new TableHost(fn (Table $table): Table => $table
        ->query($entityManager->createQueryBuilder()->select('song')->from(Song::class, 'song'))
        ->columns([TextColumn::make('title')->searchable()])
        ->splitSearchTerms(false));

    $host->tableSearch = 'love song';

    expect($host->getTable()->shouldSplitSearchTerms())->toBeFalse()
        ->and(array_keys($host->getTableRecords()->getRecords()))->toBe([1])
        ->and($host->getFilteredTableQuery()?->getParameter('sylament_search_0')?->getValue())->toBe('%love song%');
});

it('splits search terms unless a closure says otherwise', function (): void {
    $unconfigured = new TableHost();
    $host = new TableHost(fn (Table $table): Table => $table->splitSearchTerms(fn (): bool => false));

    expect($unconfigured->getTable()->shouldSplitSearchTerms())->toBeTrue()
        ->and($host->getTable()->shouldSplitSearchTerms())->toBeFalse();
});

it('respects quoted phrases when splitting', function (): void {
    $entityManager = searchableSongs();
    $host = new TableHost(fn (Table $table): Table => $table
        ->query($entityManager->createQueryBuilder()->select('song')->from(Song::class, 'song'))
        ->columns([TextColumn::make('title')->searchable()]));

    $host->tableSearch = '"love song"';

    expect(array_keys($host->getTableRecords()->getRecords()))->toBe([1])
        ->and($host->getFilteredTableQuery()?->getParameter('sylament_search_0')?->getValue())->toBe('%love song%');
});

it('splits on the Unicode blanks that are not whitespace', function (): void {
    $entityManager = searchableSongs();
    $host = new TableHost(fn (Table $table): Table => $table
        ->query($entityManager->createQueryBuilder()->select('song')->from(Song::class, 'song'))
        ->columns([TextColumn::make('title')->searchable()]));

    $host->tableSearch = "silent\u{3164}\u{1160}love";

    expect(array_keys($host->getTableRecords()->getRecords()))->toBe([2])
        ->and($host->getFilteredTableQuery()?->getParameter('sylament_search_0')?->getValue())->toBe('%silent%')
        ->and($host->getFilteredTableQuery()?->getParameter('sylament_search_1')?->getValue())->toBe('%love%');
});

it('bypasses column assembly through searchUsing', function (): void {
    $entityManager = searchableSongs();
    $received = null;
    $host = new TableHost(function (Table $table) use (&$received, $entityManager): Table {
        return $table
            ->query($entityManager->createQueryBuilder()->select('song')->from(Song::class, 'song'))
            ->columns([TextColumn::make('title')->searchable()])
            ->searchUsing(function (QueryBuilder $query, string $search) use (&$received): void {
                $received = $search;

                $query->andWhere('song.plays > 15');
            });
    });

    $host->tableSearch = 'anything';

    $dql = $host->getFilteredTableQuery()?->getDQL() ?? '';

    expect($host->getTable()->hasSearchUsingCallback())->toBeTrue()
        ->and(array_keys($host->getTableRecords()->getRecords()))->toBe([2, 3])
        ->and($received)->toBe('anything')
        ->and($dql)->toContain('WHERE song.plays > 15')
        ->and($dql)->not->toContain('LIKE');
});

it('composes extra searchable columns and closure members', function (): void {
    $entityManager = searchableSongs();
    $configure = fn (Table $table): Table => $table
        ->query($entityManager->createQueryBuilder()->select('song')->from(Song::class, 'song'))
        ->columns([TextColumn::make('title')])
        ->searchable(['title', fn (string $search): ?string => $search === 'loud' ? 'song.plays > 25' : null]);
    $viaColumn = new TableHost($configure);
    $viaClosure = new TableHost($configure);

    $viaColumn->tableSearch = 'silent';
    $viaClosure->tableSearch = 'loud';

    expect(array_keys($viaColumn->getTableRecords()->getRecords()))->toBe([2])
        ->and(array_keys($viaClosure->getTableRecords()->getRecords()))->toBe([3])
        ->and($viaColumn->getFilteredTableQuery()?->getDQL())->toContain('WHERE song.title LIKE :sylament_search_0')
        ->and($viaColumn->getFilteredTableQuery()?->getDQL())->not->toContain('song.plays')
        ->and($viaClosure->getFilteredTableQuery()?->getDQL())
        ->toContain('WHERE song.title LIKE :sylament_search_0 OR song.plays > 25');
});

it('skips an extra-column closure that constrained the query itself', function (): void {
    $entityManager = searchableSongs();
    $host = new TableHost(fn (Table $table): Table => $table
        ->query($entityManager->createQueryBuilder()->select('song')->from(Song::class, 'song'))
        ->columns([TextColumn::make('title')])
        ->searchable(['title', fn (QueryBuilder $query): QueryBuilder => $query->andWhere('song.plays > 15')]));

    $host->tableSearch = 'love';

    expect(array_keys($host->getTableRecords()->getRecords()))->toBe([2])
        ->and($host->getFilteredTableQuery()?->getDQL())
        ->toContain('WHERE song.plays > 15 AND song.title LIKE :sylament_search_0');
});

it('applies no where at all when nothing is searchable', function (): void {
    $entityManager = searchableSongs();
    $host = new TableHost(fn (Table $table): Table => $table
        ->query($entityManager->createQueryBuilder()->select('song')->from(Song::class, 'song'))
        ->columns([TextColumn::make('title')])
        ->searchable());

    $host->tableSearch = 'foo';

    expect(array_keys($host->getTableRecords()->getRecords()))->toBe([1, 2, 3])
        ->and($host->getFilteredTableQuery()?->getDQL())->not->toContain('WHERE');
});

it('leaves the query untouched for a blank search', function (): void {
    $entityManager = searchableSongs();
    $configure = fn (Table $table): Table => $table
        ->query($entityManager->createQueryBuilder()->select('song')->from(Song::class, 'song'))
        ->columns([TextColumn::make('title')->searchable()]);
    $blank = new TableHost($configure);
    $unsearched = new TableHost($configure);

    $blank->tableSearch = '   ';

    $query = $blank->getFilteredTableQuery();

    expect($query?->getDQL())->toBe($unsearched->getFilteredTableQuery()?->getDQL())
        ->and($query?->getParameters()->count())->toBe(0)
        ->and(array_keys($blank->getTableRecords()->getRecords()))->toBe([1, 2, 3]);
});

it('marks case arms in the DQL, not the results', function (): void {
    $entityManager = searchableSongs();
    $sensitive = new TableHost(fn (Table $table): Table => $table
        ->query($entityManager->createQueryBuilder()->select('song')->from(Song::class, 'song'))
        ->columns([TextColumn::make('title')->searchable()]));
    $insensitive = new TableHost(fn (Table $table): Table => $table
        ->query($entityManager->createQueryBuilder()->select('song')->from(Song::class, 'song'))
        ->columns([TextColumn::make('title')->searchable()->forceSearchCaseInsensitive()]));

    $sensitive->tableSearch = 'Love';
    $insensitive->tableSearch = 'Love';

    $sensitiveQuery = $sensitive->getFilteredTableQuery();
    $insensitiveQuery = $insensitive->getFilteredTableQuery();

    expect($sensitiveQuery?->getDQL())->toContain('WHERE song.title LIKE :sylament_search_0')
        ->and($sensitiveQuery?->getDQL())->not->toContain('LOWER(')
        ->and($sensitiveQuery?->getParameter('sylament_search_0')?->getValue())->toBe('%Love%')
        ->and($insensitiveQuery?->getDQL())->toContain('WHERE LOWER(song.title) LIKE :sylament_search_0')
        ->and($insensitiveQuery?->getParameter('sylament_search_0')?->getValue())->toBe('%love%');
});

it('names search parameters uniquely across words and columns', function (): void {
    $entityManager = searchableSongs();
    $host = new TableHost(fn (Table $table): Table => $table
        ->query($entityManager->createQueryBuilder()->select('song')->from(Song::class, 'song'))
        ->columns([TextColumn::make('title')->searchable(), TextColumn::make('plays')->searchable()]));

    $host->tableSearch = 'love song';

    $query = $host->getFilteredTableQuery();

    expect($query?->getParameters()->count())->toBe(4)
        ->and($query?->getParameter('sylament_search_0')?->getValue())->toBe('%love%')
        ->and($query?->getParameter('sylament_search_1')?->getValue())->toBe('%love%')
        ->and($query?->getParameter('sylament_search_2')?->getValue())->toBe('%song%')
        ->and($query?->getParameter('sylament_search_3')?->getValue())->toBe('%song%')
        ->and($query?->getQuery()->getSQL())->toContain('LIKE ? OR');
});
