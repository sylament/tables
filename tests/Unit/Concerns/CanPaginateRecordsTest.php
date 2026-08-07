<?php

declare(strict_types=1);

use Doctrine\ORM\EntityManager;
use Sylament\Support\Pagination\Paginator;
use Sylament\Tables\Enums\PaginationMode;
use Sylament\Tables\Table;
use Sylament\Tables\Tests\Fixtures\Concerns\TableHost;
use Sylament\Tables\Tests\Fixtures\Doctrine\Entity\Artist;
use Sylament\Tables\Tests\Fixtures\Doctrine\Entity\Song;
use Sylament\Tables\Tests\Fixtures\Doctrine\EntityManagerFactory;

/*
 * Covers the query half of host-side pagination — the slice `paginateTableQuery()`
 * takes and the counts it hands back. The `$tablePage` / `$tableRecordsPerPage`
 * state it reads stays in `HasTableTest`.
 */

function pagedSongs(int $count): EntityManager
{
    $entityManager = EntityManagerFactory::create();
    $songs = [];

    foreach (range(1, $count) as $number) {
        $songs[] = new Song($number, "Song {$number}", $number * 10);
    }

    EntityManagerFactory::seed($entityManager, ...$songs);

    return $entityManager;
}

function pagedArtists(): EntityManager
{
    $entityManager = EntityManagerFactory::create();
    $artists = [new Artist(1, 'Alpha'), new Artist(2, 'Bravo'), new Artist(3, 'Charlie')];
    $songs = [];

    foreach ($artists as $index => $artist) {
        foreach (range(1, 3) as $number) {
            $songs[] = new Song($index * 3 + $number, "Song {$number}", $number * 10, $artist);
        }
    }

    EntityManagerFactory::seed($entityManager, ...$artists, ...$songs);

    return $entityManager;
}

it('paginates the query lane with a true total and sliced records', function (): void {
    $entityManager = pagedSongs(7);
    $host = new TableHost(fn (Table $table): Table => $table
        ->query($entityManager->createQueryBuilder()->select('song')->from(Song::class, 'song'))
        ->paginated([2, 5]));

    $firstPage = $host->getTableRecords();

    expect($firstPage)->toBeInstanceOf(Paginator::class)
        ->and(array_keys($firstPage->getRecords()))->toBe([1, 2])
        ->and($firstPage->getPage())->toBe(1)
        ->and($firstPage->getRecordsPerPage())->toBe(2)
        ->and($firstPage->getTotalRecordCount())->toBe(7)
        ->and($firstPage->getLastPage())->toBe(4);

    $host->gotoTablePage(3);

    $thirdPage = $host->getTableRecords();

    expect(array_keys($thirdPage->getRecords()))->toBe([5, 6])
        ->and($thirdPage->getPage())->toBe(3)
        ->and($thirdPage->getTotalRecordCount())->toBe(7);
});

it('renders a page past the end empty with the total intact', function (): void {
    $entityManager = pagedSongs(7);
    $host = new TableHost(fn (Table $table): Table => $table
        ->query($entityManager->createQueryBuilder()->select('song')->from(Song::class, 'song'))
        ->paginated([2, 5]));

    $host->gotoTablePage(99);

    $paginator = $host->getTableRecords();

    expect($paginator->getRecords())->toBe([])
        ->and($paginator->getPage())->toBe(99)
        ->and($paginator->getTotalRecordCount())->toBe(7)
        ->and($paginator->getLastPage())->toBe(4);
});

it('shows everything under the all option', function (): void {
    $entityManager = pagedSongs(7);
    $host = new TableHost(fn (Table $table): Table => $table
        ->query($entityManager->createQueryBuilder()->select('song')->from(Song::class, 'song'))
        ->paginated([2, 'all']));

    $host->tableRecordsPerPage = 'all';

    $paginator = $host->getTableRecords();

    expect(array_keys($paginator->getRecords()))->toBe([1, 2, 3, 4, 5, 6, 7])
        ->and($paginator->getRecordsPerPage())->toBe('all')
        ->and($paginator->getTotalRecordCount())->toBe(7)
        ->and($paginator->getLastPage())->toBe(1);
});

it('slices by a numeric-string page size as an integer', function (): void {
    $entityManager = pagedSongs(11);
    $host = new TableHost(fn (Table $table): Table => $table
        ->query($entityManager->createQueryBuilder()->select('song')->from(Song::class, 'song'))
        ->paginated(['10', 25]));

    $paginator = $host->getTableRecords();

    expect($paginator->getRecords())->toHaveCount(10)
        ->and($paginator->getRecordsPerPage())->toBe(10)
        ->and($paginator->getTotalRecordCount())->toBe(11);
});

it('probes one extra record in simple mode instead of counting', function (): void {
    $entityManager = pagedSongs(5);
    $configure = fn (Table $table): Table => $table
        ->query($entityManager->createQueryBuilder()->select('song')->from(Song::class, 'song'))
        ->paginated([2, 5])
        ->paginationMode(PaginationMode::Simple);
    $host = new TableHost($configure);
    $lastPageHost = new TableHost($configure);

    $lastPageHost->gotoTablePage(3);

    $firstPage = $host->getTableRecords();
    $lastPage = $lastPageHost->getTableRecords();

    expect(array_keys($firstPage->getRecords()))->toBe([1, 2])
        ->and($firstPage->getTotalRecordCount())->toBeNull()
        ->and($firstPage->getLastPage())->toBeNull()
        ->and($firstPage->hasMorePages())->toBeTrue()
        ->and(array_keys($lastPage->getRecords()))->toBe([5])
        ->and($lastPage->getTotalRecordCount())->toBeNull()
        ->and($lastPage->hasMorePages())->toBeFalse();
});

it('degenerates simple mode to a known total under the all option', function (): void {
    $entityManager = pagedSongs(5);
    $host = new TableHost(fn (Table $table): Table => $table
        ->query($entityManager->createQueryBuilder()->select('song')->from(Song::class, 'song'))
        ->paginated([2, 'all'])
        ->paginationMode(PaginationMode::Simple));

    $host->tableRecordsPerPage = 'all';

    $paginator = $host->getTableRecords();

    expect($paginator->getRecords())->toHaveCount(5)
        ->and($paginator->getRecordsPerPage())->toBe('all')
        ->and($paginator->getTotalRecordCount())->toBe(5)
        ->and($paginator->hasMorePages())->toBeFalse();
});

it('refuses cursor pagination naming the gap', function (): void {
    $entityManager = pagedSongs(3);
    $host = new TableHost(fn (Table $table): Table => $table
        ->query($entityManager->createQueryBuilder()->select('song')->from(Song::class, 'song'))
        ->paginationMode(PaginationMode::Cursor));

    $host->getTableRecords();
})->throws(LogicException::class, 'cursor');

it('paginates entities, not joined rows, under a fetch join', function (): void {
    $entityManager = pagedArtists();
    $host = new TableHost(fn (Table $table): Table => $table
        ->query($entityManager->createQueryBuilder()
            ->select('artist')
            ->from(Artist::class, 'artist')
            ->leftJoin('artist.songs', 'songs')
            ->addSelect('songs'))
        ->paginated([2, 5]));

    $paginator = $host->getTableRecords();
    $records = $paginator->getRecords();

    expect($records)->toHaveCount(2)
        ->and(array_keys($records))->toBe([1, 2])
        ->and($records[1])->toBeInstanceOf(Artist::class)
        ->and($records[1]->getSongs())->toHaveCount(3)
        ->and($paginator->getTotalRecordCount())->toBe(3);
});

it('returns a plain keyed array when pagination is off', function (): void {
    $entityManager = pagedSongs(3);
    $host = new TableHost(fn (Table $table): Table => $table
        ->query($entityManager->createQueryBuilder()->select('song')->from(Song::class, 'song'))
        ->paginated(false));

    $records = $host->getTableRecords();

    expect($records)->toBeArray()
        ->and(array_keys($records))->toBe([1, 2, 3])
        ->and($host->getTablePaginator())->toBeNull();
});
