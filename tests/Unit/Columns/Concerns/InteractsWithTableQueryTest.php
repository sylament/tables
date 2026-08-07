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

it('resolves alias-headed dotted names and refuses unknown heads', function (): void {
    $entityManager = EntityManagerFactory::create();
    $joined = $entityManager->createQueryBuilder()
        ->select('song')->from(Song::class, 'song')
        ->leftJoin('song.artist', 'artist');
    $unjoined = $entityManager->createQueryBuilder()->select('song')->from(Song::class, 'song');
    $table = new TableHost(fn (Table $table): Table => $table
        ->columns([TextColumn::make('artist.name')->sortable()]))->getTable();
    $column = $table->getColumn('artist.name');

    $column?->applySort($joined);

    expect($joined->getDQL())->toContain('ORDER BY artist.name asc')
        ->and($joined->getQuery()->getSQL())->toContain('ORDER BY');
    expect(fn (): ?QueryBuilder => $column?->applySort($unjoined))->toThrow(LogicException::class, 'is not an alias on the builder');
});

it('answers its sort and search column lists', function (): void {
    expect(TextColumn::make('title')->sortable()->getSortColumns())->toBe(['title'])
        ->and(TextColumn::make('title')->sortable(['plays'])->getSortColumns())->toBe(['plays'])
        ->and(TextColumn::make('title')->searchable()->getSearchColumns())->toBe(['title'])
        ->and(TextColumn::make('title')->searchable('slug')->getSearchColumns())->toBe(['slug'])
        ->and(TextColumn::make('title')->getSortColumns())->toBe([])
        ->and(TextColumn::make('title')->getSearchColumns())->toBe(['title']);
});
