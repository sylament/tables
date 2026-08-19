<?php

declare(strict_types=1);

use Doctrine\ORM\Query\Expr\Join;
use Sylament\Tables\Query\JoinCardinality;
use Sylament\Tables\Tests\Fixtures\Doctrine\Entity\Artist;
use Sylament\Tables\Tests\Fixtures\Doctrine\Entity\Genre;
use Sylament\Tables\Tests\Fixtures\Doctrine\Entity\Song;
use Sylament\Tables\Tests\Fixtures\Doctrine\EntityManagerFactory;

it('is false with no joins', function (): void {
    $query = EntityManagerFactory::create()->createQueryBuilder()->select('s')->from(Song::class, 's');

    expect(JoinCardinality::joinsCollection($query))->toBeFalse();
});

it('is false for a to-one association join', function (): void {
    $query = EntityManagerFactory::create()->createQueryBuilder()
        ->select('s')->from(Song::class, 's')
        ->leftJoin('s.artist', 'sy_artist');

    expect(JoinCardinality::joinsCollection($query))->toBeFalse();
});

it('is false for a chain of to-one joins', function (): void {
    $query = EntityManagerFactory::create()->createQueryBuilder()
        ->select('s')->from(Song::class, 's')
        ->leftJoin('s.artist', 'sy_artist')
        ->leftJoin('sy_artist.label', 'sy_artist_label');

    expect(JoinCardinality::joinsCollection($query))->toBeFalse();
});

it('resolves parents through earlier joins', function (): void {
    $query = EntityManagerFactory::create()->createQueryBuilder()
        ->select('s')->from(Song::class, 's')
        ->leftJoin('s.artist', 'x')
        ->leftJoin('x.label', 'y');

    expect(JoinCardinality::joinsCollection($query))->toBeFalse();
});

it('is true for a to-many association join', function (): void {
    $owning = EntityManagerFactory::create()->createQueryBuilder()
        ->select('s')->from(Song::class, 's')
        ->leftJoin('s.genres', 'sy_genres');
    $inverse = EntityManagerFactory::create()->createQueryBuilder()
        ->select('a')->from(Artist::class, 'a')
        ->leftJoin('a.songs', 'sy_songs');

    expect(JoinCardinality::joinsCollection($owning))->toBeTrue()
        ->and(JoinCardinality::joinsCollection($inverse))->toBeTrue();
});

it('is true for a to-many join reached through a to-one chain', function (): void {
    $query = EntityManagerFactory::create()->createQueryBuilder()
        ->select('s')->from(Song::class, 's')
        ->leftJoin('s.artist', 'sy_artist')
        ->leftJoin('sy_artist.songs', 'sy_artist_songs');

    expect(JoinCardinality::joinsCollection($query))->toBeTrue();
});

it('is true for an arbitrary-class join', function (): void {
    $query = EntityManagerFactory::create()->createQueryBuilder()
        ->select('s')->from(Song::class, 's')
        ->leftJoin(Genre::class, 'g', Join::ON, 'g.id = 1');

    expect(JoinCardinality::joinsCollection($query))->toBeTrue();
});

it('is true when a join parent alias cannot be resolved', function (): void {
    $query = EntityManagerFactory::create()->createQueryBuilder()
        ->select('s')->from(Song::class, 's')
        ->leftJoin('ghost.artist', 'sy_ghost');

    expect(JoinCardinality::joinsCollection($query))->toBeTrue();
});

it('is true for a join on a property that is no association', function (): void {
    $query = EntityManagerFactory::create()->createQueryBuilder()
        ->select('a')->from(Artist::class, 'a')
        ->leftJoin('a.address', 'sy_address');

    expect(JoinCardinality::joinsCollection($query))->toBeTrue();
});
