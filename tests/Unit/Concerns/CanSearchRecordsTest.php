<?php

declare(strict_types=1);

use Doctrine\ORM\EntityManager;
use Doctrine\ORM\QueryBuilder;
use Sylament\Tables\Columns\TextColumn;
use Sylament\Tables\Table;
use Sylament\Tables\Tests\Fixtures\Concerns\TableHost;
use Sylament\Tables\Tests\Fixtures\Doctrine\Entity\Address;
use Sylament\Tables\Tests\Fixtures\Doctrine\Entity\Artist;
use Sylament\Tables\Tests\Fixtures\Doctrine\Entity\Genre;
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

/**
 * One song carrying two genres and an artist, one carrying an artist alone, and
 * one carrying neither — so a to-many term matching twice, a term matching
 * across rows, and a term matching nothing related all have a subject.
 */
function searchableLibrary(): EntityManager
{
    $entityManager = EntityManagerFactory::create();

    $radiohead = new Artist(1, 'Radiohead', address: new Address('Main St'));
    $muse = new Artist(2, 'Muse', address: new Address('Back Rd'));
    $rock = new Genre(1, 'Rock');
    $pop = new Genre(2, 'Pop');

    $alpha = new Song(1, 'Alpha', 10, $radiohead);
    $alpha->getGenres()->add($rock);
    $alpha->getGenres()->add($pop);

    EntityManagerFactory::seed(
        $entityManager,
        $radiohead,
        $muse,
        $rock,
        $pop,
        $alpha,
        new Song(2, 'Origin of Symmetry', 20, $muse),
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

it('searches a to-one relation column', function (): void {
    $entityManager = searchableLibrary();
    $host = new TableHost(fn (Table $table): Table => $table
        ->query($entityManager->createQueryBuilder()->select('song')->from(Song::class, 'song'))
        ->columns([TextColumn::make('title')->searchable(), TextColumn::make('artist.name')->searchable()]));

    $host->tableSearch = 'radio';

    $query = $host->getFilteredTableQuery();

    expect(array_keys($host->getTableRecords()->getRecords()))->toBe([1])
        ->and($query?->getDQL())->toContain(
            'song.title LIKE :sylament_search_0',
            'EXISTS (SELECT sy_x1.id FROM ' . Song::class . ' sy_x1 JOIN sy_x1.artist sy_x1_0 WHERE sy_x1.id = song.id AND sy_x1_0.name LIKE :sylament_search_1)',
        )
        ->and($query?->getDQLPart('join'))->toBe([]);
});

it('searches a to-many relation column without duplicating roots', function (): void {
    $entityManager = searchableLibrary();
    $configure = fn (Table $table): Table => $table
        ->query($entityManager->createQueryBuilder()->select('song')->from(Song::class, 'song'))
        ->columns([TextColumn::make('genres.name')->searchable()]);
    $oneGenre = new TableHost($configure);
    $bothGenres = new TableHost($configure);

    $oneGenre->tableSearch = 'ro';
    $bothGenres->tableSearch = 'o';

    expect($oneGenre->getFilteredTableQuery()?->getQuery()->getResult())->toHaveCount(1)
        ->and($bothGenres->getFilteredTableQuery()?->getQuery()->getResult())->toHaveCount(1)
        ->and($bothGenres->getAllTableRecordsCount())->toBe(1)
        ->and(array_keys($bothGenres->getTableRecords()->getRecords()))->toBe([1])
        ->and($bothGenres->getFilteredTableQuery()?->getDQLPart('join'))->toBe([]);
});

it('composes multi-word search as AND of per-word ORs across relation and plain columns', function (): void {
    $entityManager = searchableLibrary();
    $configure = fn (Table $table): Table => $table
        ->query($entityManager->createQueryBuilder()->select('song')->from(Song::class, 'song'))
        ->columns([TextColumn::make('title')->searchable(), TextColumn::make('artist.name')->searchable()]);
    $acrossRows = new TableHost($configure);
    $sameRow = new TableHost($configure);

    $acrossRows->tableSearch = 'muse alpha';
    $sameRow->tableSearch = 'muse origin';

    expect($acrossRows->getTableRecords()->getRecords())->toBe([])
        ->and(array_keys($sameRow->getTableRecords()->getRecords()))->toBe([2])
        ->and($sameRow->getFilteredTableQuery()?->getDQL())->toContain(
            'sy_x1_0.name LIKE :sylament_search_1',
            'song.title LIKE :sylament_search_2',
            'sy_x3_0.name LIKE :sylament_search_3',
        );
});

it('keeps the count in step with a relation search', function (): void {
    $entityManager = searchableLibrary();
    $configure = fn (Table $table): Table => $table
        ->query($entityManager->createQueryBuilder()->select('song')->from(Song::class, 'song')->orderBy('song.id'))
        ->columns([TextColumn::make('title')->searchable(), TextColumn::make('genres.name')->searchable()])
        ->paginated([1, 5]);
    $counted = new TableHost($configure);
    $paged = new TableHost($configure);

    $counted->tableSearch = 'o';
    $paged->tableSearch = 'o';

    $paginator = $paged->getTableRecords();

    expect($counted->getAllTableRecordsCount())->toBe(3)
        ->and($paginator->getTotalRecordCount())->toBe(3)
        ->and($paginator->getLastPage())->toBe(3)
        ->and(array_keys($paginator->getRecords()))->toBe([1]);
});

it('searches an embeddable path as a plain field', function (): void {
    $entityManager = searchableLibrary();
    $host = new TableHost(fn (Table $table): Table => $table
        ->query($entityManager->createQueryBuilder()->select('artist')->from(Artist::class, 'artist'))
        ->columns([TextColumn::make('address.street')->searchable()]));

    $host->tableSearch = 'main';

    $query = $host->getFilteredTableQuery();

    expect(array_keys($host->getTableRecords()->getRecords()))->toBe([1])
        ->and($query?->getDQL())->toContain('WHERE artist.address.street LIKE :sylament_search_0')
        ->and($query?->getDQL())->not->toContain('EXISTS');
});

it('table-level searchable([...]) reaches a relation path', function (): void {
    $entityManager = searchableLibrary();
    $host = new TableHost(fn (Table $table): Table => $table
        ->query($entityManager->createQueryBuilder()->select('song')->from(Song::class, 'song'))
        ->columns([TextColumn::make('title')])
        ->searchable(['artist.name']));

    $host->tableSearch = 'muse';

    $query = $host->getFilteredTableQuery();

    expect(array_keys($host->getTableRecords()->getRecords()))->toBe([2])
        ->and($query?->getDQL())
        ->toContain('EXISTS (SELECT sy_x0.id FROM ' . Song::class . ' sy_x0 JOIN sy_x0.artist sy_x0_0 WHERE sy_x0.id = song.id AND sy_x0_0.name LIKE :sylament_search_0)')
        ->and($query?->getDQLPart('join'))->toBe([]);
});

it('leaves a hidden column out of the global search group', function (): void {
    $entityManager = searchableSongs();
    $configure = fn (bool $isHidden): Closure => fn (Table $table): Table => $table
        ->query($entityManager->createQueryBuilder()->select('song')->from(Song::class, 'song'))
        ->columns([TextColumn::make('title')->searchable()->hidden($isHidden)]);

    $visible = new TableHost($configure(false));
    $hidden = new TableHost($configure(true));

    $visible->tableSearch = 'Silent';
    $hidden->tableSearch = 'Silent';

    expect($visible->getFilteredTableQuery()?->getDQL() ?? '')->toContain('LIKE')
        ->and($hidden->getFilteredTableQuery()?->getDQL() ?? '')->not->toContain('LIKE')
        ->and($hidden->getFilteredTableQuery()?->getDQLPart('where'))->toBeNull();
});

it('seeds every individually searchable column, nesting a dotted name', function (): void {
    $host = new TableHost(fn (Table $table): Table => $table->columns([
        TextColumn::make('title')->searchable(isIndividual: true),
        TextColumn::make('artist.name')->searchable(isIndividual: true),
        TextColumn::make('plays')->searchable(),
    ]));

    $host->mountTableColumnSearches();

    expect($host->tableColumnSearches)->toBe(['title' => '', 'artist' => ['name' => '']]);
});

it('flattens and trims the bag and keeps the raw value for the input', function (): void {
    $host = new TableHost(fn (Table $table): Table => $table->columns([TextColumn::make('artist.name')->searchable(isIndividual: true)]));

    $host->tableColumnSearches = ['artist' => ['name' => ' Radio ']];

    expect($host->getTableColumnSearches())->toBe(['artist.name' => 'Radio'])
        ->and($host->getTableColumnSearch('artist.name'))->toBe(' Radio ')
        ->and($host->getTableColumnSearch('title'))->toBe('');
});

it('constrains only visible, individually searchable columns, one group per word', function (): void {
    $entityManager = searchableLibrary();
    $host = new TableHost(fn (Table $table): Table => $table
        ->query($entityManager->createQueryBuilder()->select('song')->from(Song::class, 'song'))
        ->columns([
            TextColumn::make('title')->searchable(isIndividual: true),
            TextColumn::make('plays')->searchable(),
            TextColumn::make('secret')->searchable(isIndividual: true)->hidden(),
        ]));

    $host->tableColumnSearches = ['title' => 'air bag', 'plays' => '100', 'secret' => 'x'];

    $dql = $host->getFilteredTableQuery()?->getDQL() ?? '';

    expect(mb_substr_count($dql, 'LIKE'))->toBe(2)
        ->and($dql)->not->toContain('plays')
        ->and($dql)->not->toContain('secret');
});

it('honours the column term-splitting override over the table default', function (): void {
    $entityManager = searchableLibrary();
    $configure = fn (bool $split): Closure => fn (Table $table): Table => $table
        ->query($entityManager->createQueryBuilder()->select('song')->from(Song::class, 'song'))
        ->columns([TextColumn::make('title')->searchable(isIndividual: true)->splitIndividualSearchTerms($split)])
        ->splitSearchTerms(!$split);

    $split = new TableHost($configure(true));
    $whole = new TableHost($configure(false));
    $deferring = new TableHost(fn (Table $table): Table => $table
        ->query($entityManager->createQueryBuilder()->select('song')->from(Song::class, 'song'))
        ->columns([TextColumn::make('title')->searchable(isIndividual: true)])
        ->splitSearchTerms(false));

    $split->tableColumnSearches = ['title' => 'air bag'];
    $whole->tableColumnSearches = ['title' => 'air bag'];
    $deferring->tableColumnSearches = ['title' => 'air bag'];

    expect(mb_substr_count($split->getFilteredTableQuery()?->getDQL() ?? '', 'LIKE'))->toBe(2)
        ->and(mb_substr_count($whole->getFilteredTableQuery()?->getDQL() ?? '', 'LIKE'))->toBe(1)
        ->and(mb_substr_count($deferring->getFilteredTableQuery()?->getDQL() ?? '', 'LIKE'))->toBe(1);
});

it('leaves a seeded blank out of the query and out of the chips, splitting or not', function (): void {
    $entityManager = searchableLibrary();
    $host = new TableHost(fn (Table $table): Table => $table
        ->query($entityManager->createQueryBuilder()->select('song')->from(Song::class, 'song'))
        ->columns([
            TextColumn::make('title')->searchable(isIndividual: true),
            TextColumn::make('artist.name')->searchable(isIndividual: true),
        ])
        ->splitSearchTerms(false));

    $host->mountTableColumnSearches();

    expect($host->tableColumnSearches)->toBe(['title' => '', 'artist' => ['name' => '']])
        ->and($host->getFilteredTableQuery()?->getDQL() ?? '')->not->toContain('LIKE')
        ->and($host->getFilteredTableQuery()?->getDQLPart('where'))->toBeNull()
        ->and($host->getTableColumnSearchIndicators())->toBe([]);
});

it('adds no group for a column whose search query constrains nothing', function (): void {
    $entityManager = searchableLibrary();
    $host = new TableHost(fn (Table $table): Table => $table
        ->query($entityManager->createQueryBuilder()->select('song')->from(Song::class, 'song'))
        ->columns([TextColumn::make('title')->searchable(query: fn (): null => null, isIndividual: true)]));

    $host->tableColumnSearches = ['title' => 'Alpha'];

    expect($host->getFilteredTableQuery()?->getDQLPart('where'))->toBeNull();
});

it('drops a numeric key rather than handing it to the column lookup', function (): void {
    $entityManager = searchableLibrary();
    $host = new TableHost(fn (Table $table): Table => $table
        ->query($entityManager->createQueryBuilder()->select('song')->from(Song::class, 'song'))
        ->columns([TextColumn::make('title')->searchable(isIndividual: true)]));

    $host->tableColumnSearches = ['0' => 'x', 'title' => 'Alpha'];

    expect($host->hydrateTableColumnSearches(['0' => 'x', 'title' => 'Alpha']))->toBe(['title' => 'Alpha'])
        ->and($host->getTableColumnSearches())->toBe(['title' => 'Alpha'])
        ->and(array_keys($host->getTableColumnSearchIndicators()))->toBe(['title'])
        ->and(mb_substr_count($host->getFilteredTableQuery()?->getDQL() ?? '', 'LIKE'))->toBe(1);
});

it('hydrates a wire value that is not an array as an empty bag', function (): void {
    $host = new TableHost(fn (Table $table): Table => $table->columns([TextColumn::make('title')->searchable(isIndividual: true)]));

    expect($host->hydrateTableColumnSearches('not-an-array'))->toBe([]);
});

it('runs the update choreography when the hydrated bag differs from what arrived', function (): void {
    $host = new TableHost(fn (Table $table): Table => $table->columns([TextColumn::make('title')->searchable(isIndividual: true)])->paginated([2]));
    $host->tablePage = 3;

    $host->hydrateTableColumnSearches(['title' => '']);
    $host->tableColumnSearches = ['title' => 'air'];
    $host->afterTableColumnSearchesHydrated();

    expect($host->tablePage)->toBe(1);

    $untouched = new TableHost(fn (Table $table): Table => $table->columns([TextColumn::make('title')->searchable(isIndividual: true)]));
    $untouched->tablePage = 3;

    $untouched->hydrateTableColumnSearches(['title' => 'air']);
    $untouched->tableColumnSearches = ['title' => 'air'];
    $untouched->afterTableColumnSearchesHydrated();

    expect($untouched->tablePage)->toBe(3);
});

it('keeps the first hydrated bag as the snapshot, so a later update never overwrites it', function (): void {
    $host = new TableHost(fn (Table $table): Table => $table->columns([TextColumn::make('title')->searchable(isIndividual: true)]));
    $host->tablePage = 3;

    $host->hydrateTableColumnSearches(['title' => '']);
    $host->hydrateTableColumnSearches(['title' => 'air']);
    $host->tableColumnSearches = ['title' => 'air'];
    $host->afterTableColumnSearchesHydrated();

    expect($host->tablePage)->toBe(1);
});

it('seeds a column that became searchable between requests without reading it as an edit', function (): void {
    $host = new TableHost(fn (Table $table): Table => $table->columns([
        TextColumn::make('title')->searchable(isIndividual: true),
        TextColumn::make('artist.name')->searchable(isIndividual: true),
    ]));
    $host->tablePage = 3;

    $host->hydrateTableColumnSearches(['title' => '']);
    $host->tableColumnSearches = ['title' => ''];
    $host->afterTableColumnSearchesHydrated();

    expect($host->tablePage)->toBe(3)
        ->and($host->tableColumnSearches)->toBe(['title' => '', 'artist' => ['name' => '']]);
});

it('casts every leaf to a string on both sides of the compare', function (): void {
    $host = new TableHost(fn (Table $table): Table => $table->columns([TextColumn::make('title')->searchable(isIndividual: true)]));
    $host->tablePage = 3;

    $host->hydrateTableColumnSearches(['title' => 100]);
    $host->tableColumnSearches = ['title' => '100'];
    $host->afterTableColumnSearchesHydrated();

    expect($host->tablePage)->toBe(3)
        ->and($host->tableColumnSearches)->toBe(['title' => '100']);
});

it('puts the nested untrimmed bag on the wire rather than the flat trimmed map', function (): void {
    $host = new TableHost(fn (Table $table): Table => $table->columns([TextColumn::make('artist.name')->searchable(isIndividual: true)]));

    $host->tableColumnSearches = ['artist' => ['name' => ' Radio ']];

    expect($host->dehydrateTableColumnSearches(['artist.name' => 'Radio']))->toBe(['artist' => ['name' => ' Radio ']]);
});

it('labels one chip per column holding a term and skips hidden and blank ones', function (): void {
    $host = new TableHost(fn (Table $table): Table => $table->columns([
        TextColumn::make('title')->searchable(isIndividual: true),
        TextColumn::make('artist.name')->searchable(isIndividual: true),
        TextColumn::make('plays')->searchable(),
        TextColumn::make('secret')->searchable(isIndividual: true)->hidden(),
    ]));

    $host->tableColumnSearches = ['title' => ' Air', 'artist' => ['name' => ''], 'plays' => '100', 'secret' => 'x'];

    $indicators = $host->getTableColumnSearchIndicators();

    expect(array_keys($indicators))->toBe(['title'])
        ->and((string) $indicators['title']->getLabel())->toBe('Title: Air');
});

it('resets one column or all of them back to a seeded blank, running the choreography each time', function (): void {
    $host = new TableHost(fn (Table $table): Table => $table->columns([
        TextColumn::make('title')->searchable(isIndividual: true),
        TextColumn::make('artist.name')->searchable(isIndividual: true),
    ]));

    $host->tableColumnSearches = ['title' => 'a', 'artist' => ['name' => 'b']];
    $host->tablePage = 3;

    $host->resetTableColumnSearch('artist.name');

    expect($host->tableColumnSearches)->toBe(['title' => 'a', 'artist' => ['name' => '']])
        ->and($host->tablePage)->toBe(1);

    $host->tablePage = 3;

    $host->resetTableColumnSearches();

    expect($host->tableColumnSearches)->toBe(['title' => '', 'artist' => ['name' => '']])
        ->and($host->tablePage)->toBe(1);
});

it('refuses to reset a column the table does not search individually', function (): void {
    $host = new TableHost(fn (Table $table): Table => $table->columns([
        TextColumn::make('title')->searchable(isIndividual: true),
        TextColumn::make('plays')->searchable(),
    ]));

    $host->tableColumnSearches = ['title' => 'a'];
    $host->tablePage = 3;

    $host->resetTableColumnSearch('plays');
    $host->resetTableColumnSearch('title.x');
    $host->resetTableColumnSearch('invented');

    expect($host->tableColumnSearches)->toBe(['title' => 'a'])
        ->and($host->tablePage)->toBe(3);
});

it('clears the selection on a per-column change exactly where the table says so', function (): void {
    $configure = fn (bool $deselects): Closure => fn (Table $table): Table => $table
        ->columns([TextColumn::make('title')->searchable(isIndividual: true)])
        ->deselectAllRecordsWhenFiltered($deselects);

    $deselecting = new TableHost($configure(true));
    $keeping = new TableHost($configure(false));

    foreach ([$deselecting, $keeping] as $host) {
        $host->selectedTableRecordKeys = ['1'];
        $host->tableColumnSearches = ['title' => 'a'];
        $host->resetTableColumnSearch('title');
    }

    expect($deselecting->selectedTableRecordKeys)->toBe([])
        ->and($keeping->selectedTableRecordKeys)->toBe(['1']);
});

it('flushes the cached records when a per-column term changes', function (): void {
    $fetches = 0;
    $host = new TableHost(function (Table $table) use (&$fetches): Table {
        return $table
            ->columns([TextColumn::make('title')->searchable(isIndividual: true)])
            ->records(function () use (&$fetches): array {
                $fetches++;

                return [];
            });
    });

    $host->tableColumnSearches = ['title' => 'Air'];
    $host->getTableRecords();
    $host->getTableRecords();

    expect($fetches)->toBe(1);

    $host->resetTableColumnSearch('title');
    $host->getTableRecords();

    expect($fetches)->toBe(2);
});
