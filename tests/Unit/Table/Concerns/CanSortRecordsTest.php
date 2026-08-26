<?php

declare(strict_types=1);

use Doctrine\ORM\QueryBuilder;
use Sylament\Schemas\State\Relationship;
use Sylament\Tables\Table;
use Sylament\Tables\Tests\Fixtures\Doctrine\Entity\Artist;
use Sylament\Tables\Tests\Fixtures\Doctrine\Entity\Genre;
use Sylament\Tables\Tests\Fixtures\Doctrine\Entity\Song;
use Sylament\Tables\Tests\Fixtures\Doctrine\EntityManagerFactory;

use function Sylament\Tables\Tests\relationshipTable;

it('orders a relation-scoped table by its mapping until a default sort is declared', function (): void {
    $artist = new Artist(1, 'Radiohead');
    $song = new Song(1, 'Airbag', 100);
    $ordered = relationshipTable(fn (): Relationship => new Relationship($artist, 'songs'));
    $declared = relationshipTable(fn (): Relationship => new Relationship($artist, 'songs'), fn (Table $table): Table => $table->defaultSort('plays', 'desc'));
    $unordered = relationshipTable(fn (): Relationship => new Relationship($song, 'genres'));

    $query = $ordered->getQuery();
    assert($query instanceof QueryBuilder);
    $sorted = $ordered->getDefaultSort($query, 'asc');

    expect($sorted)->toBe($query)
        ->and($query->getDQL())->toEndWith('ORDER BY record.title asc')
        ->and($declared->getDefaultSort($declared->getQuery() ?? throw new LogicException(), 'desc'))->toBe('plays')
        ->and($unordered->getDefaultSort($unordered->getQuery() ?? throw new LogicException(), 'asc'))->toBeNull();
});

it("appends the mapping's own direction, not a hardcoded ascending one", function (): void {
    // `Artist::$albums` is the one fixture mapping ordered DESC, so this is the
    // only case that can tell the direction map apart from a literal 'asc' —
    // and the caller's own direction is the opposite of the one that lands.
    $artist = new Artist(1, 'Radiohead');
    $table = relationshipTable(fn (): Relationship => new Relationship($artist, 'albums'));

    $query = $table->getQuery();
    assert($query instanceof QueryBuilder);

    expect($table->getDefaultSort($query, 'asc'))->toBe($query)
        ->and($query->getDQL())->toEndWith('ORDER BY record.title desc');
});

it('leaves a table whose query() won over its relationship unordered, with the closure unevaluated', function (): void {
    $entityManager = EntityManagerFactory::create();
    $artist = new Artist(1, 'Radiohead');
    $genres = $entityManager->createQueryBuilder()->select('genre')->from(Genre::class, 'genre');
    $calls = 0;
    $relationship = function () use (&$calls, $artist): Relationship {
        $calls++;

        return new Relationship($artist, 'songs');
    };

    $table = relationshipTable($relationship, fn (Table $table): Table => $table->query($genres));
    $query = $table->getQuery();
    assert($query instanceof QueryBuilder);

    expect($table->getDefaultSort($query, 'asc'))->toBeNull()
        ->and($query->getDQL())->not->toContain('ORDER BY')
        ->and($calls)->toBe(0);
});
