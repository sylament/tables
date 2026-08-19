<?php

declare(strict_types=1);

use Doctrine\ORM\Query\Expr\Comparison;
use Doctrine\ORM\QueryBuilder;
use Sylament\Tables\Columns\TextColumn;
use Sylament\Tables\Table;
use Sylament\Tables\Tests\Fixtures\Concerns\TableHost;
use Sylament\Tables\Tests\Fixtures\Doctrine\Entity\Song;
use Sylament\Tables\Tests\Fixtures\Doctrine\EntityManagerFactory;

it('applies the default sort column in declared direction', function (): void {
    $entityManager = EntityManagerFactory::create();
    $query = $entityManager->createQueryBuilder()->select('song')->from(Song::class, 'song');
    $table = new TableHost(fn (Table $table): Table => $table
        ->query(fn (): QueryBuilder => $entityManager->createQueryBuilder()->select('song')->from(Song::class, 'song'))
        ->columns([TextColumn::make('title')->sortable()]))->getTable();

    $table->getColumn('title')?->applySort($query, 'desc');

    expect($query->getDQL())->toContain('ORDER BY song.title desc');
});

it('applies a sort-column list in declared order', function (): void {
    $query = EntityManagerFactory::create()->createQueryBuilder()->select('song')->from(Song::class, 'song');
    $table = new TableHost(fn (Table $table): Table => $table
        ->columns([TextColumn::make('title')->sortable(['plays', 'title'])]))->getTable();

    $sorted = $table->getColumn('title')?->applySort($query);

    expect($sorted?->getDQL())->toContain('ORDER BY song.plays asc, song.title asc');
});

it('hands a sortable(query:) closure the query and direction and applies nothing else', function (): void {
    $query = EntityManagerFactory::create()->createQueryBuilder()->select('song')->from(Song::class, 'song');
    $table = new TableHost(fn (Table $table): Table => $table->columns([
        TextColumn::make('title')->sortable(query: function (QueryBuilder $query, string $direction): void {
            $query->addOrderBy('song.plays', $direction);
        }),
    ]))->getTable();

    $table->getColumn('title')?->applySort($query, 'desc');

    expect($query->getDQL())->toContain('ORDER BY song.plays desc')
        ->and($query->getDQL())->not->toContain('song.title');
});

it('composes the default search columns into the Or expression', function (): void {
    $entityManager = EntityManagerFactory::create();
    $query = $entityManager->createQueryBuilder()->select('song')->from(Song::class, 'song');
    $searchExpression = $query->expr()->orX();
    $table = new TableHost(fn (Table $table): Table => $table
        ->columns([TextColumn::make('title')->searchable()]))->getTable();

    $table->getColumn('title')?->applySearchConstraint($query, $searchExpression, 'foo');

    expect((string) $searchExpression)->toBe('song.title LIKE :sylament_search_0')
        ->and($query->getParameter('sylament_search_0')?->getValue())->toBe('%foo%');

    $listQuery = $entityManager->createQueryBuilder()->select('song')->from(Song::class, 'song');
    $listExpression = $listQuery->expr()->orX();
    $listTable = new TableHost(fn (Table $table): Table => $table
        ->columns([TextColumn::make('title')->searchable(['title', 'plays'])]))->getTable();

    $listTable->getColumn('title')?->applySearchConstraint($listQuery, $listExpression, 'foo');

    expect((string) $listExpression)->toBe('song.title LIKE :sylament_search_0 OR song.plays LIKE :sylament_search_1')
        ->and($listQuery->getParameter('sylament_search_1')?->getValue())->toBe('%foo%');
});

it('adds a searchable(query:) closure return to the expression and skips what it applied itself', function (): void {
    $query = EntityManagerFactory::create()->createQueryBuilder()->select('song')->from(Song::class, 'song');
    $returned = $query->expr()->orX();
    $returnedDql = $query->expr()->orX();
    $skipped = $query->expr()->orX();
    $applied = $query->expr()->orX();
    $injected = [];
    $returning = new TableHost(fn (Table $table): Table => $table->columns([
        TextColumn::make('title')->searchable(query: fn (QueryBuilder $query, string $search): Comparison => $query->expr()
            ->eq('song.title', $query->expr()->literal($search))),
    ]))->getTable();
    $returningDql = new TableHost(fn (Table $table): Table => $table->columns([
        TextColumn::make('title')->searchable(query: fn (): string => 'song.plays > 0'),
    ]))->getTable();
    $silent = new TableHost(function (Table $table) use (&$injected): Table {
        return $table->columns([
            TextColumn::make('title')->searchable(query: function (QueryBuilder $query, string $search, string $searchQuery) use (&$injected): void {
                $injected = ['query' => $query, 'search' => $search, 'searchQuery' => $searchQuery];
            }),
        ]);
    })->getTable();
    $applying = new TableHost(fn (Table $table): Table => $table->columns([
        TextColumn::make('title')->searchable(query: fn (QueryBuilder $query): QueryBuilder => $query->andWhere('song.plays > 15')),
    ]))->getTable();

    $returning->getColumn('title')?->applySearchConstraint($query, $returned, 'foo');
    $returningDql->getColumn('title')?->applySearchConstraint($query, $returnedDql, 'foo');
    $silent->getColumn('title')?->applySearchConstraint($query, $skipped, 'foo');
    $applying->getColumn('title')?->applySearchConstraint($query, $applied, 'foo');

    expect((string) $returned)->toBe("song.title = 'foo'")
        ->and((string) $returnedDql)->toBe('song.plays > 0')
        ->and($skipped->count())->toBe(0)
        ->and($injected['query'])->toBe($query)
        ->and($injected['search'])->toBe('foo')
        ->and($injected['searchQuery'])->toBe('foo')
        ->and($applied->count())->toBe(0)
        ->and($query->getDQL())->toContain('WHERE song.plays > 15');
});

it('forces case-insensitivity per column', function (): void {
    $query = EntityManagerFactory::create()->createQueryBuilder()->select('song')->from(Song::class, 'song');
    $searchExpression = $query->expr()->orX();
    $table = new TableHost(fn (Table $table): Table => $table->columns([
        TextColumn::make('title')->searchable()->forceSearchCaseInsensitive(),
    ]))->getTable();

    $table->getColumn('title')?->applySearchConstraint($query, $searchExpression, 'Foo');

    expect((string) $searchExpression)->toBe('LOWER(song.title) LIKE :sylament_search_0')
        ->and($query->getParameter('sylament_search_0')?->getValue())->toBe('%foo%')
        ->and(TextColumn::make('title')->isSearchForcedCaseInsensitive())->toBeNull()
        ->and(TextColumn::make('title')->forceSearchCaseInsensitive(fn (): bool => true)->isSearchForcedCaseInsensitive())->toBeTrue();
});

it('resolves alias-headed dotted names, joins unaliased ones, and refuses unknown heads', function (): void {
    $entityManager = EntityManagerFactory::create();
    $joined = $entityManager->createQueryBuilder()
        ->select('song')->from(Song::class, 'song')
        ->leftJoin('song.artist', 'artist');
    $unjoined = $entityManager->createQueryBuilder()->select('song')->from(Song::class, 'song');
    $unknown = $entityManager->createQueryBuilder()->select('song')->from(Song::class, 'song');
    $table = new TableHost(fn (Table $table): Table => $table->columns([
        TextColumn::make('artist.name')->sortable(),
        TextColumn::make('publisher.name')->sortable(),
    ]))->getTable();
    $column = $table->getColumn('artist.name');

    $column?->applySort($joined);
    $column?->applySort($unjoined);

    expect($joined->getDQL())->toContain('ORDER BY artist.name asc')
        ->and($joined->getQuery()->getSQL())->toContain('ORDER BY')
        ->and($unjoined->getDQL())->toContain('LEFT JOIN song.artist sy_artist', 'ORDER BY sy_artist.name asc')
        ->and($unjoined->getQuery()->getSQL())->toContain('ORDER BY');
    expect(fn (): ?QueryBuilder => $table->getColumn('publisher.name')?->applySort($unknown))
        ->toThrow(LogicException::class, 'is not an alias on the builder, a mapped field path, or an association path');
});

it('leaves an alias-headed search path to the caller and still refuses an unknown head', function (): void {
    $entityManager = EntityManagerFactory::create();
    $joined = $entityManager->createQueryBuilder()
        ->select('song')->from(Song::class, 'song')
        ->leftJoin('song.artist', 'artist');
    $unknown = $entityManager->createQueryBuilder()->select('song')->from(Song::class, 'song');
    $joinedExpression = $joined->expr()->orX();
    $table = new TableHost(fn (Table $table): Table => $table->columns([
        TextColumn::make('artist.name')->searchable(),
        TextColumn::make('publisher.name')->searchable(),
    ]))->getTable();

    $table->getColumn('artist.name')?->applySearchConstraint($joined, $joinedExpression, 'foo');

    expect((string) $joinedExpression)->toBe('artist.name LIKE :sylament_search_0')
        ->and($joined->getDQL())->not->toContain('EXISTS');
    expect(fn (): mixed => $table->getColumn('publisher.name')?->applySearchConstraint($unknown, $unknown->expr()->orX(), 'foo'))
        ->toThrow(LogicException::class, 'is not an alias on the builder, a mapped field path, or an association path');
});

it('fetch-joins a to-one relation column, selecting every alias it crosses', function (): void {
    $entityManager = EntityManagerFactory::create();
    $oneHop = $entityManager->createQueryBuilder()->select('song')->from(Song::class, 'song');
    $twoHops = $entityManager->createQueryBuilder()->select('song')->from(Song::class, 'song');
    $embedded = $entityManager->createQueryBuilder()->select('song')->from(Song::class, 'song');
    $table = new TableHost(fn (Table $table): Table => $table->columns([
        TextColumn::make('artist.name'),
        TextColumn::make('artist.label.name'),
        TextColumn::make('artist.address.street'),
    ]))->getTable();

    $table->getColumn('artist.name')?->applyEagerLoading($oneHop);
    $table->getColumn('artist.label.name')?->applyEagerLoading($twoHops);
    $table->getColumn('artist.address.street')?->applyEagerLoading($embedded);

    expect($oneHop->getDQL())->toContain('SELECT song, sy_artist')
        ->and($oneHop->getDQL())->toContain('LEFT JOIN song.artist sy_artist')
        ->and($twoHops->getDQL())->toContain('SELECT song, sy_artist, sy_artist_label')
        ->and($twoHops->getDQL())->toContain('LEFT JOIN song.artist sy_artist LEFT JOIN sy_artist.label sy_artist_label')
        ->and($embedded->getDQL())->toContain('SELECT song, sy_artist')
        ->and($embedded->getDQL())->toContain('LEFT JOIN song.artist sy_artist');
});

it('reuses the sort join for eager loading and selects the alias once', function (): void {
    $query = EntityManagerFactory::create()->createQueryBuilder()->select('song')->from(Song::class, 'song');
    $table = new TableHost(fn (Table $table): Table => $table->columns([
        TextColumn::make('artist.name')->sortable(),
    ]))->getTable();
    $column = $table->getColumn('artist.name');

    $column?->applySort($query);
    $column?->applyEagerLoading($query);
    $column?->applyEagerLoading($query);

    expect(mb_substr_count($query->getDQL(), 'LEFT JOIN'))->toBe(1)
        ->and($query->getDQL())->toContain('SELECT song, sy_artist FROM')
        ->and($query->getDQL())->toContain('ORDER BY sy_artist.name asc');
});

it('leaves a dotless, to-many, alias-headed, unmapped or association-terminal column unjoined', function (): void {
    $entityManager = EntityManagerFactory::create();
    $queries = [];
    $table = new TableHost(fn (Table $table): Table => $table->columns([
        TextColumn::make('title'),
        TextColumn::make('genres.name'),
        TextColumn::make('artist.name'),
        TextColumn::make('publisher.name'),
        TextColumn::make('artist.label'),
    ]))->getTable();

    foreach (['title', 'genres.name', 'artist.name', 'publisher.name', 'artist.label'] as $name) {
        $queries[$name] = $entityManager->createQueryBuilder()->select('song')->from(Song::class, 'song');
    }

    $queries['artist.name']->leftJoin('song.artist', 'artist');

    foreach ($queries as $name => $query) {
        $table->getColumn($name)?->applyEagerLoading($query);
    }

    expect($queries['title']->getDQL())->toBe('SELECT song FROM ' . Song::class . ' song')
        ->and($queries['genres.name']->getDQL())->toBe('SELECT song FROM ' . Song::class . ' song')
        ->and($queries['publisher.name']->getDQL())->toBe('SELECT song FROM ' . Song::class . ' song')
        ->and($queries['artist.label']->getDQL())->toBe('SELECT song FROM ' . Song::class . ' song')
        ->and($queries['artist.name']->getDQL())->toContain('SELECT song FROM')
        ->and($queries['artist.name']->getDQL())->not->toContain('sy_artist');
});

it('answers its sort and search column lists', function (): void {
    expect(TextColumn::make('title')->sortable()->getSortColumns())->toBe(['title'])
        ->and(TextColumn::make('title')->sortable(['plays'])->getSortColumns())->toBe(['plays'])
        ->and(TextColumn::make('title')->searchable()->getSearchColumns())->toBe(['title'])
        ->and(TextColumn::make('title')->searchable('slug')->getSearchColumns())->toBe(['slug'])
        ->and(TextColumn::make('title')->getSortColumns())->toBe([])
        ->and(TextColumn::make('title')->getSearchColumns())->toBe(['title']);
});
