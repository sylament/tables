<?php

declare(strict_types=1);

use Sylament\Tables\Query\Hop;
use Sylament\Tables\Query\RelationJoiner;
use Sylament\Tables\Tests\Fixtures\Doctrine\Entity\Artist;
use Sylament\Tables\Tests\Fixtures\Doctrine\Entity\Label;
use Sylament\Tables\Tests\Fixtures\Doctrine\Entity\Song;
use Sylament\Tables\Tests\Fixtures\Doctrine\EntityManagerFactory;

it('left-joins each hop with a deterministic sy_ alias', function (): void {
    $query = EntityManagerFactory::create()->createQueryBuilder()->select('s')->from(Song::class, 's');
    $hops = [new Hop('artist', Artist::class, false), new Hop('label', Label::class, false)];

    $alias = RelationJoiner::ensureJoined($query, 's', $hops);

    expect($alias)->toBe('sy_artist_label')
        ->and($query->getDQL())->toContain('LEFT JOIN s.artist sy_artist LEFT JOIN sy_artist.label sy_artist_label');
});

it('returns the parent alias untouched for an empty hop list', function (): void {
    $query = EntityManagerFactory::create()->createQueryBuilder()->select('s')->from(Song::class, 's');

    $alias = RelationJoiner::ensureJoined($query, 's', []);

    expect($alias)->toBe('s')
        ->and($query->getDQLPart('join'))->toBe([]);
});

it('reuses an existing join for the same parent alias and property', function (): void {
    $query = EntityManagerFactory::create()->createQueryBuilder()
        ->select('s')->from(Song::class, 's')
        ->leftJoin('s.artist', 'writer');

    $alias = RelationJoiner::ensureJoined($query, 's', [new Hop('artist', Artist::class, false)]);

    expect($alias)->toBe('writer')
        ->and($query->getDQLPart('join')['s'])->toHaveCount(1);
});

it('is idempotent across calls', function (): void {
    $query = EntityManagerFactory::create()->createQueryBuilder()->select('s')->from(Song::class, 's');
    $hops = [new Hop('artist', Artist::class, false), new Hop('label', Label::class, false)];

    $first = RelationJoiner::ensureJoined($query, 's', $hops);
    $second = RelationJoiner::ensureJoined($query, 's', $hops);

    expect($second)->toBe($first)
        ->and($query->getDQLPart('join')['s'])->toHaveCount(2);
});

it('suffixes a minted alias that collides', function (): void {
    $query = EntityManagerFactory::create()->createQueryBuilder()
        ->select('s')->from(Song::class, 's')
        ->leftJoin('s.genres', 'sy_artist');

    $alias = RelationJoiner::ensureJoined($query, 's', [new Hop('artist', Artist::class, false)]);

    expect($alias)->toBe('sy_artist_2')
        ->and($query->getDQL())->toContain('LEFT JOIN s.artist sy_artist_2');
});

it('keeps suffixing until the minted alias is free', function (): void {
    $query = EntityManagerFactory::create()->createQueryBuilder()
        ->select('s')->from(Song::class, 's')
        ->leftJoin('s.genres', 'sy_artist')
        ->leftJoin('s.genres', 'sy_artist_2');

    $alias = RelationJoiner::ensureJoined($query, 's', [new Hop('artist', Artist::class, false)]);

    expect($alias)->toBe('sy_artist_3');
});
