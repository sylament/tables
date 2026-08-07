<?php

declare(strict_types=1);

use Sylament\Tables\Query\ColumnPathResolver;
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
