<?php

declare(strict_types=1);

use Sylament\Tables\Query\RelationPathResolver;
use Sylament\Tables\Tests\Fixtures\Doctrine\Entity\Artist;
use Sylament\Tables\Tests\Fixtures\Doctrine\Entity\Genre;
use Sylament\Tables\Tests\Fixtures\Doctrine\Entity\Label;
use Sylament\Tables\Tests\Fixtures\Doctrine\Entity\Song;
use Sylament\Tables\Tests\Fixtures\Doctrine\EntityManagerFactory;

it('returns null for a dotless path', function (): void {
    $query = EntityManagerFactory::create()->createQueryBuilder()->select('song')->from(Song::class, 'song');

    expect(RelationPathResolver::analyseQueryPath($query, 'title'))->toBeNull();
});

it('returns null when the head is an existing builder alias', function (): void {
    $query = EntityManagerFactory::create()->createQueryBuilder()
        ->select('song')->from(Song::class, 'song')
        ->leftJoin('song.artist', 'artist');

    expect(RelationPathResolver::analyseQueryPath($query, 'artist.name'))->toBeNull();
});

it('returns null when the query declares no root entity', function (): void {
    $query = EntityManagerFactory::create()->createQueryBuilder()->select('song');

    expect(RelationPathResolver::analyseQueryPath($query, 'artist.name'))->toBeNull();
});

it('resolves a to-one hop with a field remainder', function (): void {
    $query = EntityManagerFactory::create()->createQueryBuilder()->select('song')->from(Song::class, 'song');

    $resolved = RelationPathResolver::analyseQueryPath($query, 'artist.name');

    expect($resolved)->not->toBeNull()
        ->and($resolved?->isRelationPath())->toBeTrue()
        ->and($resolved?->hasToManyHop())->toBeFalse()
        ->and($resolved?->hops)->toHaveCount(1)
        ->and($resolved?->hops[0]->property)->toBe('artist')
        ->and($resolved?->hops[0]->targetClass)->toBe(Artist::class)
        ->and($resolved?->hops[0]->isToMany)->toBeFalse()
        ->and($resolved?->fieldPath)->toBe('name');
});

it('resolves a nested to-one chain', function (): void {
    $query = EntityManagerFactory::create()->createQueryBuilder()->select('song')->from(Song::class, 'song');

    $resolved = RelationPathResolver::analyseQueryPath($query, 'artist.label.name');

    expect($resolved?->hops)->toHaveCount(2)
        ->and($resolved?->hops[0]->property)->toBe('artist')
        ->and($resolved?->hops[0]->targetClass)->toBe(Artist::class)
        ->and($resolved?->hops[1]->property)->toBe('label')
        ->and($resolved?->hops[1]->targetClass)->toBe(Label::class)
        ->and($resolved?->hasToManyHop())->toBeFalse()
        ->and($resolved?->fieldPath)->toBe('name');
});

it('marks a to-many hop on the owning side', function (): void {
    $query = EntityManagerFactory::create()->createQueryBuilder()->select('song')->from(Song::class, 'song');

    $resolved = RelationPathResolver::analyseQueryPath($query, 'genres.name');

    expect($resolved?->hops)->toHaveCount(1)
        ->and($resolved?->hops[0]->property)->toBe('genres')
        ->and($resolved?->hops[0]->targetClass)->toBe(Genre::class)
        ->and($resolved?->hops[0]->isToMany)->toBeTrue()
        ->and($resolved?->hasToManyHop())->toBeTrue()
        ->and($resolved?->fieldPath)->toBe('name');
});

it('marks a to-many hop on the inverse side', function (): void {
    $query = EntityManagerFactory::create()->createQueryBuilder()->select('artist')->from(Artist::class, 'artist');

    $resolved = RelationPathResolver::analyseQueryPath($query, 'songs.title');

    expect($resolved?->hops)->toHaveCount(1)
        ->and($resolved?->hops[0]->property)->toBe('songs')
        ->and($resolved?->hops[0]->targetClass)->toBe(Song::class)
        ->and($resolved?->hops[0]->isToMany)->toBeTrue()
        ->and($resolved?->hasToManyHop())->toBeTrue()
        ->and($resolved?->fieldPath)->toBe('title');
});

it('resolves an embeddable dotted field with zero hops', function (): void {
    $query = EntityManagerFactory::create()->createQueryBuilder()->select('artist')->from(Artist::class, 'artist');

    $resolved = RelationPathResolver::analyseQueryPath($query, 'address.street');

    expect($resolved)->not->toBeNull()
        ->and($resolved?->hops)->toBe([])
        ->and($resolved?->isRelationPath())->toBeFalse()
        ->and($resolved?->hasToManyHop())->toBeFalse()
        ->and($resolved?->fieldPath)->toBe('address.street');
});

it('resolves an embeddable field through a hop', function (): void {
    $query = EntityManagerFactory::create()->createQueryBuilder()->select('song')->from(Song::class, 'song');

    $resolved = RelationPathResolver::analyseQueryPath($query, 'artist.address.street');

    expect($resolved?->hops)->toHaveCount(1)
        ->and($resolved?->hops[0]->property)->toBe('artist')
        ->and($resolved?->fieldPath)->toBe('address.street');
});

it('returns null when the head names nothing on the root', function (): void {
    $query = EntityManagerFactory::create()->createQueryBuilder()->select('song')->from(Song::class, 'song');

    expect(RelationPathResolver::analyseQueryPath($query, 'nonsense.name'))->toBeNull();
});

it('returns null when the remainder names nothing on the target', function (): void {
    $query = EntityManagerFactory::create()->createQueryBuilder()->select('song')->from(Song::class, 'song');

    expect(RelationPathResolver::analyseQueryPath($query, 'artist.nonsense'))->toBeNull();
});

it('throws for a dotted path ending in an association', function (): void {
    $query = EntityManagerFactory::create()->createQueryBuilder()->select('song')->from(Song::class, 'song');

    RelationPathResolver::analyseQueryPath($query, 'artist.label');
})->throws(LogicException::class, 'artist.label');

it('answers null for the association-terminal path the strict analysis throws for', function (): void {
    $query = EntityManagerFactory::create()->createQueryBuilder()->select('song')->from(Song::class, 'song');

    expect(RelationPathResolver::tryAnalyseQueryPath($query, 'artist.label'))->toBeNull()
        ->and(fn (): mixed => RelationPathResolver::analyseQueryPath($query, 'artist.label'))
        ->toThrow(LogicException::class, 'the path ends in an association rather than a field');
});

it('resolves and refuses everything but that ending exactly as the strict analysis does', function (): void {
    $query = EntityManagerFactory::create()->createQueryBuilder()->select('song')->from(Song::class, 'song');

    expect(RelationPathResolver::tryAnalyseQueryPath($query, 'artist.name')?->fieldPath)->toBe('name')
        ->and(RelationPathResolver::tryAnalyseQueryPath($query, 'artist.label.name')?->hops)->toHaveCount(2)
        ->and(RelationPathResolver::tryAnalyseQueryPath($query, 'genres.name')?->hasToManyHop())->toBeTrue()
        ->and(RelationPathResolver::tryAnalyseQueryPath($query, 'title'))->toBeNull()
        ->and(RelationPathResolver::tryAnalyseQueryPath($query, 'nonsense.name'))->toBeNull()
        ->and(RelationPathResolver::tryAnalyseQueryPath($query, 'artist.nonsense'))->toBeNull();
});

it('analyses a path against a class without a query builder', function (): void {
    $entityManager = EntityManagerFactory::create();

    $resolved = RelationPathResolver::analyse($entityManager, Song::class, 'artist.name');

    expect($resolved?->hops)->toHaveCount(1)
        ->and($resolved?->hops[0]->targetClass)->toBe(Artist::class)
        ->and($resolved?->fieldPath)->toBe('name');
});
