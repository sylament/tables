<?php

declare(strict_types=1);

use Doctrine\ORM\QueryBuilder;
use Sylament\Tables\Table;
use Sylament\Tables\Tests\Fixtures\Concerns\TableHost;
use Sylament\Tables\Tests\Fixtures\Doctrine\Entity\Artist;
use Sylament\Tables\Tests\Fixtures\Doctrine\Entity\Song;
use Sylament\Tables\Tests\Fixtures\Doctrine\EntityManagerFactory;

it('returns a scoped clone on every read and never the stored builder', function (): void {
    $entityManager = EntityManagerFactory::create();
    $base = $entityManager->createQueryBuilder()->select('song')->from(Song::class, 'song');
    $table = new TableHost(fn (Table $table): Table => $table->query($base))->getTable();

    $first = $table->getQuery();
    $second = $table->getQuery();

    expect($first)->not->toBe($base)
        ->and($second)->not->toBe($first)
        ->and($first?->getDQL())->toBe($base->getDQL());
});

it('does not leak a caller mutation into the next read', function (): void {
    $entityManager = EntityManagerFactory::create();
    $base = $entityManager->createQueryBuilder()->select('song')->from(Song::class, 'song');
    $table = new TableHost(fn (Table $table): Table => $table->query($base))->getTable();

    $table->getQuery()?->andWhere('song.plays > 10');

    expect($table->getQuery()?->getDQL())->toBe($base->getDQL());
});

it('evaluates a closure query once and clones from the memoised base', function (): void {
    $entityManager = EntityManagerFactory::create();
    $calls = 0;
    $query = function () use (&$calls, $entityManager): QueryBuilder {
        $calls++;

        return $entityManager->createQueryBuilder()->select('song')->from(Song::class, 'song');
    };
    $table = new TableHost(fn (Table $table): Table => $table->query($query))->getTable();

    $first = $table->getQuery();
    $second = $table->getQuery();

    expect($calls)->toBe(1)
        ->and($second)->not->toBe($first);

    $table->query($query);
    $table->getQuery();

    expect($calls)->toBe(2);
});

it('applies scopes in order and keeps the query when a scope returns null', function (): void {
    $entityManager = EntityManagerFactory::create();
    $base = $entityManager->createQueryBuilder()->select('song')->from(Song::class, 'song');
    $table = new TableHost(fn (Table $table): Table => $table->query($base)
        ->modifyQueryUsing(fn (QueryBuilder $query): QueryBuilder => $query->andWhere('song.plays > 1'))
        ->modifyQueryUsing(function (QueryBuilder $query): void {
            $query->andWhere('song.plays < 100');
        }))->getTable();

    expect($table->getQuery()?->getDQL())->toBe(
        'SELECT song FROM ' . Song::class . ' song WHERE song.plays > 1 AND song.plays < 100',
    );
});

it('hands scopes the isResolvingRecord flag', function (): void {
    $entityManager = EntityManagerFactory::create();
    $base = $entityManager->createQueryBuilder()->select('song')->from(Song::class, 'song');
    $seen = [];
    $scope = function (QueryBuilder $query, bool $isResolvingRecord) use (&$seen): void {
        $seen[] = $isResolvingRecord;
    };
    $table = new TableHost(fn (Table $table): Table => $table->query($base)->modifyQueryUsing($scope))->getTable();

    $table->getQuery();
    $table->getQuery(isResolvingRecord: true);

    expect($seen)->toBe([false, true]);
});

it('answers null for a table with no declared query', function (): void {
    expect(new TableHost()->getTable()->getQuery())->toBeNull();
});

it('applies scopes to a builder the caller hands the public seam', function (): void {
    $entityManager = EntityManagerFactory::create();
    $table = new TableHost(fn (Table $table): Table => $table
        ->modifyQueryUsing(fn (QueryBuilder $query): QueryBuilder => $query->andWhere('song.plays > 1')))->getTable();
    $query = $entityManager->createQueryBuilder()->select('song')->from(Song::class, 'song');

    expect($table->applyQueryScopes($query))->toBe($query)
        ->and($query->getDQL())->toBe('SELECT song FROM ' . Song::class . ' song WHERE song.plays > 1');
});

it('reaches the entity manager behind the declared query', function (): void {
    $entityManager = EntityManagerFactory::create();
    $base = $entityManager->createQueryBuilder()->select('song')->from(Song::class, 'song');
    $table = new TableHost(fn (Table $table): Table => $table->query($base))->getTable();

    expect($table->getQueryEntityManager())->toBe($entityManager)
        ->and(new TableHost()->getTable()->getQueryEntityManager())->toBeNull();
});

it('is on the query lane exactly while no records closure is set', function (): void {
    $entityManager = EntityManagerFactory::create();
    $base = $entityManager->createQueryBuilder()->select('song')->from(Song::class, 'song');
    $table = new TableHost(fn (Table $table): Table => $table->query($base))->getTable();

    expect(new TableHost()->getTable()->hasQuery())->toBeTrue()
        ->and($table->hasQuery())->toBeTrue();

    $table->records(fn (): array => []);

    expect($table->hasQuery())->toBeFalse();

    $table->records(null);

    expect($table->hasQuery())->toBeTrue();
});

it('reads and caches the record class off the base query', function (): void {
    $entityManager = EntityManagerFactory::create();
    $calls = 0;
    $query = function () use (&$calls, $entityManager): QueryBuilder {
        $calls++;

        return $entityManager->createQueryBuilder()->select('song')->from(Song::class, 'song');
    };
    $table = new TableHost(fn (Table $table): Table => $table->query($query))->getTable();

    expect(new TableHost()->getTable()->getRecordClass())->toBeNull()
        ->and($table->getRecordClass())->toBe(Song::class)
        ->and($table->getRecordClass())->toBe(Song::class)
        ->and($calls)->toBe(1);

    $table->query($entityManager->createQueryBuilder()->select('artist')->from(Artist::class, 'artist'));

    expect($table->getRecordClass())->toBe(Artist::class);
});
