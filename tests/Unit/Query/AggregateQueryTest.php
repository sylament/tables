<?php

declare(strict_types=1);

use Doctrine\ORM\EntityManager;
use Doctrine\ORM\QueryBuilder;
use Sylament\Tables\Query\AggregateDeclaration;
use Sylament\Tables\Query\AggregateQuery;
use Sylament\Tables\Tests\Fixtures\Doctrine\Entity\Artist;
use Sylament\Tables\Tests\Fixtures\Doctrine\Entity\CompositePair;
use Sylament\Tables\Tests\Fixtures\Doctrine\Entity\Gig;
use Sylament\Tables\Tests\Fixtures\Doctrine\Entity\Song;
use Sylament\Tables\Tests\Fixtures\Doctrine\Entity\Venue;
use Sylament\Tables\Tests\Fixtures\Doctrine\EntityManagerFactory;
use Sylament\Tables\Tests\Fixtures\Doctrine\Handle;

/**
 * Radiohead with two songs, Adele with none, Björk with one — so a batch over
 * all three exercises a multi-row group, an absent key and a single-row group.
 */
function aggregateFixtureEntityManager(): EntityManager
{
    $entityManager = EntityManagerFactory::create();

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

function aggregateDeclaration(string $function, string $relationshipPath, ?string $column = null, ?Closure $scope = null): AggregateDeclaration
{
    return new AggregateDeclaration(
        function: $function,
        relationshipPath: $relationshipPath,
        column: $column,
        scope: $scope,
        castToBool: false,
    );
}

it('counts a to-many relation grouped by record key', function (): void {
    $values = AggregateQuery::fetch(
        aggregateFixtureEntityManager(),
        Artist::class,
        aggregateDeclaration('COUNT', 'songs'),
        [1, 2, 3],
    );

    expect($values)->toBe([1 => 2, 3 => 1]);
});

it('averages a related column', function (): void {
    $values = AggregateQuery::fetch(
        aggregateFixtureEntityManager(),
        Artist::class,
        aggregateDeclaration('AVG', 'songs', 'plays'),
        [1, 2, 3],
    );

    expect($values)->toBe([1 => 15.0, 3 => 300.0]);
});

it('applies the scope closure to the batch builder', function (): void {
    $scope = static fn (QueryBuilder $query, string $alias): QueryBuilder => $query->andWhere("{$alias}.plays > 100");

    $values = AggregateQuery::fetch(
        aggregateFixtureEntityManager(),
        Artist::class,
        aggregateDeclaration('COUNT', 'songs', scope: $scope),
        [1, 2, 3],
    );

    expect($values)->toBe([3 => 1]);
});

it('keeps the key narrowing when a scope replaces the where clause', function (): void {
    $scope = static fn (QueryBuilder $query, string $alias): QueryBuilder => $query->where("{$alias}.plays > 15");

    $values = AggregateQuery::fetch(
        aggregateFixtureEntityManager(),
        Artist::class,
        aggregateDeclaration('COUNT', 'songs', scope: $scope),
        [1],
    );

    expect($values)->toBe([1 => 1]);
});

it('walks a dotted relationship path', function (): void {
    $values = AggregateQuery::fetch(
        aggregateFixtureEntityManager(),
        Song::class,
        aggregateDeclaration('COUNT', 'artist.songs'),
        [1, 2, 3],
    );

    expect($values)->toBe([1 => 2, 2 => 2, 3 => 1]);
});

it('keys a batch by a stringable identifier', function (): void {
    $entityManager = EntityManagerFactory::create();
    $arena = new Venue(new Handle('arena'), 'The Arena');
    $shed = new Venue(new Handle('shed'), 'The Shed');

    EntityManagerFactory::seed(
        $entityManager,
        $arena,
        $shed,
        new Gig(1, 'Opening', 400, $arena),
        new Gig(2, 'Encore', 900, $arena),
        new Gig(3, 'Matinee', 50, $shed),
    );

    $values = AggregateQuery::fetch(
        $entityManager,
        Venue::class,
        aggregateDeclaration('COUNT', 'gigs'),
        ['arena', 'shed'],
    );

    expect($values)->toBe(['arena' => 2, 'shed' => 1]);
});

it('refuses a composite-identifier root', function (): void {
    expect(fn (): array => AggregateQuery::fetch(
        aggregateFixtureEntityManager(),
        CompositePair::class,
        aggregateDeclaration('COUNT', 'songs'),
        [1],
    ))->toThrow(LogicException::class, 'composite identifier');
});
