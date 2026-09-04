<?php

declare(strict_types=1);

use Doctrine\ORM\EntityManager;
use Doctrine\ORM\QueryBuilder;
use Sylament\Tables\Columns\Summarizers\Summarizer;
use Sylament\Tables\Columns\TextColumn;
use Sylament\Tables\Contracts\InteractsWithTable;
use Sylament\Tables\Table;
use Sylament\Tables\Tests\Fixtures\Concerns\TableHost;
use Sylament\Tables\Tests\Fixtures\Doctrine\Entity\Artist;
use Sylament\Tables\Tests\Fixtures\Doctrine\Entity\Song;
use Sylament\Tables\Tests\Fixtures\Doctrine\EntityManagerFactory;

/**
 * Two songs under one artist, so a summary over the query reads a number no
 * single row carries.
 */
function summarizerSongs(): EntityManager
{
    $entityManager = EntityManagerFactory::create();
    $radiohead = new Artist(1, 'Radiohead');

    EntityManagerFactory::seed(
        $entityManager,
        $radiohead,
        new Song(id: 1, title: 'Airbag', plays: 10, artist: $radiohead),
        new Song(id: 2, title: 'Let Down', plays: 20, artist: $radiohead),
    );

    return $entityManager;
}

/**
 * The summarizer under test, attached to a `plays` column of a table over the
 * songs query — or of a `records()` table when no entity manager is given.
 */
function summarizerOnSongs(Summarizer $summarizer, ?EntityManager $entityManager = null, ?TextColumn $column = null): Summarizer
{
    $host = new TableHost(fn (Table $table): Table => $entityManager instanceof EntityManager
        ? $table->query($entityManager->createQueryBuilder()->select('song')->from(Song::class, 'song'))
        : $table->records(fn (): array => []));

    $summarizer->column(($column ?? TextColumn::make('plays'))->table(Table::make($host)));

    $query = $host->getFilteredTableQuery();

    return $query instanceof QueryBuilder ? $summarizer->query($query) : $summarizer;
}

it('makes a configured summarizer and carries an id', function (): void {
    expect(Summarizer::make()->getId())->toBeNull()
        ->and(Summarizer::make('total')->getId())->toBe('total')
        ->and(Summarizer::make()->id('later')->getId())->toBe('later');
});

it('renders through the summarizer partial by default', function (): void {
    expect(Summarizer::make()->getTemplate())->toBe('@SylamentTables/partials/Summarizer/index.html.twig');
});

/*
 * The base summarizes nothing and batches nothing: `getSelectStatements()` returning
 * empty is the opt-out of the batched select, and `hasSelectedState()` is what tells a
 * batched null apart from an absent one.
 */
it('summarizes nothing, batches nothing and holds no selected state', function (): void {
    $summarizer = summarizerOnSongs(Summarizer::make(), summarizerSongs());

    expect($summarizer->getSelectStatements('song.plays'))->toBe([])
        ->and($summarizer->getSelectedState())->toBeNull()
        ->and($summarizer->hasSelectedState())->toBeFalse()
        ->and($summarizer->getState())->toBeNull();
});

it('takes a using() closure over the summarize() body, with the column name beside the query', function (): void {
    $summarizer = summarizerOnSongs(
        Summarizer::make()->using(fn (string $attribute, QueryBuilder $query): mixed => $query->select("MAX(song.{$attribute})")->getQuery()->getSingleScalarResult()),
        summarizerSongs(),
    );

    expect($summarizer->getState())->toEqual(20);
});

/*
 * Both refusals name `using()` as the way out, so both have to let one through: a
 * closure computing the figure itself needs neither the aggregate the column reads nor
 * an expression the qualifier would refuse to build.
 */
it('lets a using() closure past the aggregate column refusal', function (): void {
    $summarizer = summarizerOnSongs(
        Summarizer::make()->using(fn (): int => 7),
        summarizerSongs(),
        TextColumn::make('genres_count')->counts('genres'),
    );

    expect($summarizer->getState())->toBe(7);
});

it('lets a using() closure past a path the qualifier refuses', function (): void {
    $summarizer = summarizerOnSongs(
        Summarizer::make()->using(fn (string $attribute): string => $attribute),
        summarizerSongs(),
        TextColumn::make('genres.name'),
    );

    expect($summarizer->getState())->toBe('genres.name');
});

it('injects the live component, the table, the query and itself into a closure', function (): void {
    $injected = [];

    $summarizer = summarizerOnSongs(
        Summarizer::make()->using(function (mixed $liveComponent, mixed $table, mixed $query, mixed $summarizer) use (&$injected): mixed {
            $injected = [$liveComponent, $table, $query, $summarizer];

            return null;
        }),
        summarizerSongs(),
    );

    $summarizer->getState();

    expect($injected[0])->toBeInstanceOf(InteractsWithTable::class)
        ->and($injected[1])->toBeInstanceOf(Table::class)
        ->and($injected[2])->toBeInstanceOf(QueryBuilder::class)
        ->and($injected[3])->toBe($summarizer);
});

/*
 * D28: a `records()` table was never queried, so there is nothing to aggregate over —
 * and a silent null in the summary row would read as an empty set rather than as an
 * unsupported lane.
 */
it('refuses to summarize a records() table, naming the lane', function (): void {
    $summarizer = summarizerOnSongs(Summarizer::make());

    expect(fn (): mixed => $summarizer->getState())
        ->toThrow(LogicException::class, 'this table lists records() rows the toolkit never queried');
});

/*
 * D24: an aggregate column's value is filled in per record from a side store, and DQL
 * cannot aggregate a subselect, so the only honest answers are a refusal or a wrong
 * number one row away from a right one.
 */
it('refuses to summarize an aggregate column, naming the escape hatch', function (): void {
    $summarizer = summarizerOnSongs(Summarizer::make(), summarizerSongs(), TextColumn::make('genres_count')->counts('genres'));

    expect(fn (): mixed => $summarizer->getState())
        ->toThrow(LogicException::class, 'Summarizer::using()');
});

it('exposes the formatted state to its template once', function (): void {
    $summarizer = summarizerOnSongs(Summarizer::make()->using(fn (): int => 42)->prefix('~'), summarizerSongs());

    expect($summarizer->getExtraTemplateData())->toBe(['state' => '~42']);
});

/*
 * A pair is the one array a closure still meets whole, because the two ends are
 * produced together — a range's presets take the pair and answer with one, and the
 * template renders whichever ends came back rather than formatting them again.
 */
it('exposes a pair to its template whole, where a list goes entry by entry', function (): void {
    $summarizer = summarizerOnSongs(
        Summarizer::make()->using(fn (): array => [10, 300])->formatStateUsing(fn (array $state): array => [$state[0] . 'ish', $state[1] . 'ish']),
        summarizerSongs(),
    );

    expect($summarizer->getExtraTemplateData())->toBe(['state' => ['10ish', '300ish']]);
});
