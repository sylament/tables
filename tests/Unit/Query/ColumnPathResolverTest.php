<?php

declare(strict_types=1);

use Sylament\Tables\Query\ColumnPathResolver;
use Sylament\Tables\Tests\Fixtures\Doctrine\Entity\Artist;
use Sylament\Tables\Tests\Fixtures\Doctrine\Entity\Song;
use Sylament\Tables\Tests\Fixtures\Doctrine\EntityManagerFactory;

it('qualifies a bare name with the root alias', function (): void {
    $query = EntityManagerFactory::create()->createQueryBuilder()->select('song')->from(Song::class, 'song');

    expect(ColumnPathResolver::resolve($query, 'title'))->toBe('song.title');
});

it('passes a dotted path through when its head is a declared alias', function (): void {
    $query = EntityManagerFactory::create()->createQueryBuilder()
        ->select('song')->from(Song::class, 'song')
        ->leftJoin('song.artist', 'artist');

    expect(ColumnPathResolver::resolve($query, 'artist.name'))->toBe('artist.name');
});

it('refuses a dotted path whose head is not an alias', function (): void {
    $query = EntityManagerFactory::create()->createQueryBuilder()->select('song')->from(Song::class, 'song');

    ColumnPathResolver::resolve($query, 'publisher.name');
})->throws(LogicException::class, 'sortable(query:)');

it('refuses a query that declares no root alias', function (): void {
    $query = EntityManagerFactory::create()->createQueryBuilder()->select('song');

    ColumnPathResolver::resolve($query, 'title');
})->throws(LogicException::class, 'no root alias');

it('names the field and association arms in its refusal', function (): void {
    $query = EntityManagerFactory::create()->createQueryBuilder()->select('song')->from(Song::class, 'song');

    expect(fn (): string => ColumnPathResolver::resolve($query, 'publisher.name'))
        ->toThrow(LogicException::class, 'Cannot resolve [publisher.name] on the table query: [publisher] is not an alias on the builder, a mapped field path, or an association path on [' . Song::class . ']. Join and alias the relation in query(), or take over with sortable(query:)/searchable(query:); dotted paths into JSON columns are not supported in the query lane.');
});

it('resolveOrderable qualifies a bare name with the root alias', function (): void {
    $query = EntityManagerFactory::create()->createQueryBuilder()->select('song')->from(Song::class, 'song');

    expect(ColumnPathResolver::resolveOrderable($query, 'title'))->toBe('song.title')
        ->and($query->getDQLPart('join'))->toBe([]);
});

it('resolveOrderable joins a to-one path and returns the joined field', function (): void {
    $query = EntityManagerFactory::create()->createQueryBuilder()->select('song')->from(Song::class, 'song');

    expect(ColumnPathResolver::resolveOrderable($query, 'artist.label.name'))->toBe('sy_artist_label.name')
        ->and($query->getDQL())->toContain('LEFT JOIN song.artist sy_artist LEFT JOIN sy_artist.label sy_artist_label');
});

it('resolveOrderable resolves an embeddable path without joining', function (): void {
    $query = EntityManagerFactory::create()->createQueryBuilder()->select('artist')->from(Artist::class, 'artist');

    expect(ColumnPathResolver::resolveOrderable($query, 'address.street'))->toBe('artist.address.street')
        ->and($query->getDQLPart('join'))->toBe([]);
});

it('resolveOrderable keeps an alias head verbatim', function (): void {
    $query = EntityManagerFactory::create()->createQueryBuilder()
        ->select('song')->from(Song::class, 'song')
        ->leftJoin('song.artist', 'artist');

    expect(ColumnPathResolver::resolveOrderable($query, 'artist.name'))->toBe('artist.name')
        ->and($query->getDQLPart('join')['song'])->toHaveCount(1);
});

it('resolveOrderable refuses a to-many path naming the association', function (): void {
    $query = EntityManagerFactory::create()->createQueryBuilder()->select('song')->from(Song::class, 'song');

    expect(fn (): string => ColumnPathResolver::resolveOrderable($query, 'genres.name'))
        ->toThrow(LogicException::class, 'to-one')
        ->and($query->getDQLPart('join'))->toBe([]);
});

it('resolveOrderable refuses an unresolvable dotted path through resolve', function (): void {
    $query = EntityManagerFactory::create()->createQueryBuilder()->select('song')->from(Song::class, 'song');

    expect(fn (): string => ColumnPathResolver::resolveOrderable($query, 'publisher.name'))
        ->toThrow(LogicException::class, 'is not an alias on the builder, a mapped field path, or an association path');
});
