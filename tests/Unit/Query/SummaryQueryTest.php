<?php

declare(strict_types=1);

use Doctrine\ORM\EntityManager;
use Doctrine\ORM\QueryBuilder;
use Sylament\Tables\Query\SummaryQuery;
use Sylament\Tables\Tests\Fixtures\Doctrine\Entity\Artist;
use Sylament\Tables\Tests\Fixtures\Doctrine\Entity\Song;
use Sylament\Tables\Tests\Fixtures\Doctrine\EntityManagerFactory;
use Sylament\Tables\Tests\Fixtures\Doctrine\RecordingMiddleware;

/**
 * Three songs across two artists, plays 10, 20 and 300 — so a sum, a count and a
 * range each read a different number, and the middle song shares its artist with
 * the first.
 */
function summarySongsRig(?RecordingMiddleware $recorder = null): EntityManager
{
    $entityManager = EntityManagerFactory::create($recorder);

    $radiohead = new Artist(1, 'Radiohead');
    $bjork = new Artist(2, 'Björk');

    EntityManagerFactory::seed(
        $entityManager,
        $radiohead,
        $bjork,
        new Song(id: 1, title: 'Airbag', plays: 10, artist: $radiohead, position: 1),
        new Song(id: 2, title: 'Airport', plays: 20, artist: $radiohead),
        new Song(id: 3, title: 'Jóga', plays: 300, artist: $bjork, position: 3),
    );

    return $entityManager;
}

function summarySongsQuery(EntityManager $entityManager): QueryBuilder
{
    return $entityManager->createQueryBuilder()
        ->select('song')
        ->from(Song::class, 'song')
        ->orderBy('song.title', 'ASC');
}

it('reads one flat row of aliases over the query', function (): void {
    $row = SummaryQuery::readRow(summarySongsQuery(summarySongsRig()), [
        'SUM(song.plays) AS sy_summary_0',
        'COUNT(song.plays) AS sy_summary_1',
    ]);

    expect($row)->toBe(['sy_summary_0' => 330, 'sy_summary_1' => 3]);
});

/*
 * An ungrouped aggregate returns exactly one row over an empty set, so the read is a
 * `getSingleResult()` rather than a guarded list — and what an empty set means differs
 * per function, which is why the summarizers normalise rather than default.
 */
it('reads a row over an empty set with a null sum and a zero count', function (): void {
    $query = summarySongsQuery(summarySongsRig())->andWhere('song.id = 999');

    expect(SummaryQuery::readRow($query, ['SUM(song.plays) AS sy_sum', 'COUNT(song.plays) AS sy_count']))
        ->toBe(['sy_sum' => null, 'sy_count' => 0]);
});

it('aggregates a fetch-joined to-one query off the joined alias', function (): void {
    $query = summarySongsQuery(summarySongsRig())
        ->leftJoin('song.artist', 'sy_artist')
        ->addSelect('sy_artist');

    expect(SummaryQuery::readRow($query, ['MIN(sy_artist.name) AS sy_min', 'MAX(sy_artist.name) AS sy_max']))
        ->toBe(['sy_min' => 'Björk', 'sy_max' => 'Radiohead']);
});

/*
 * The order and the paging are what a stale page query carries: an `ORDER BY` on an
 * aggregate is a Postgres error, and a `LIMIT` bounds the aggregate's own result row
 * rather than the aggregated set — which reads as the all-table figure on every page.
 */
it('drops the ordering and the paging from the statement it runs', function (): void {
    $recorder = new RecordingMiddleware();
    $query = summarySongsQuery(summarySongsRig($recorder))->setFirstResult(2)->setMaxResults(1);

    $recorder->reset();

    $row = SummaryQuery::readRow($query, ['SUM(song.plays) AS sy_sum']);

    expect($row)->toBe(['sy_sum' => 330])
        ->and($recorder->statements())->toHaveCount(1)
        ->and($recorder->statements()[0])->not->toContain('ORDER BY')
        ->and($recorder->statements()[0])->not->toContain('LIMIT');
});

it('leaves the builder it was handed untouched', function (): void {
    $query = summarySongsQuery(summarySongsRig())->setMaxResults(2);
    $dql = $query->getDQL();

    SummaryQuery::readRow($query, ['SUM(song.plays) AS sy_sum']);

    expect($query->getDQL())->toBe($dql)
        ->and($query->getMaxResults())->toBe(2);
});

/*
 * A to-many join multiplies rows, so every aggregate over it reads high — three songs
 * carrying four genre rows between them count as four. A wrong number is worse than an
 * error, so the read refuses and names the escape hatch.
 */
it('refuses a query that joins a to-many association', function (): void {
    $entityManager = summarySongsRig();
    $query = summarySongsQuery($entityManager)->leftJoin('song.genres', 'sy_genres');

    expect(fn (): array => SummaryQuery::readRow($query, ['COUNT(song.id) AS sy_count']))
        ->toThrow(LogicException::class, 'Summarizer::using()');
});

it('reads a distinct column as a flat list', function (): void {
    $entityManager = summarySongsRig();
    $entityManager->persist(new Song(id: 4, title: 'Lucky', plays: 10));
    $entityManager->flush();
    $entityManager->clear();

    expect(SummaryQuery::readDistinct(summarySongsQuery($entityManager), 'song.plays'))
        ->toBe([10, 20, 300]);
});

it('reads a distinct column over an empty set as an empty list', function (): void {
    $query = summarySongsQuery(summarySongsRig())->andWhere('song.id = 999');

    expect(SummaryQuery::readDistinct($query, 'song.title'))->toBe([]);
});

it('qualifies a root field, a to-one relation path and an embeddable path', function (): void {
    $query = summarySongsQuery(summarySongsRig());

    expect(SummaryQuery::qualifyAttribute($query, 'plays'))->toBe('song.plays')
        ->and(SummaryQuery::qualifyAttribute($query, 'artist.name'))->toBe('sy_artist.name')
        ->and(SummaryQuery::qualifyAttribute($query, 'artist.address.street'))->toBe('sy_artist.address.street');
});

it('reuses a join a qualified path already crossed rather than adding a second', function (): void {
    $query = summarySongsQuery(summarySongsRig());

    SummaryQuery::qualifyAttribute($query, 'artist.name');
    SummaryQuery::qualifyAttribute($query, 'artist.label.name');

    expect(mb_substr_count($query->getDQL(), 'LEFT JOIN song.artist'))->toBe(1);
});

it('refuses to qualify a path crossing a to-many association', function (): void {
    $query = summarySongsQuery(summarySongsRig());

    expect(fn (): string => SummaryQuery::qualifyAttribute($query, 'genres.name'))
        ->toThrow(LogicException::class, 'Cannot summarize [genres.name]: summarizing through a relation supports to-one associations only.');
});
