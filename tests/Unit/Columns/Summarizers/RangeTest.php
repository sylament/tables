<?php

declare(strict_types=1);

use Doctrine\ORM\QueryBuilder;
use Sylament\Tables\Columns\Summarizers\Range;
use Sylament\Tables\Columns\TextColumn;
use Sylament\Tables\Table;
use Sylament\Tables\Tests\Fixtures\Concerns\TableHost;
use Sylament\Tables\Tests\Fixtures\Doctrine\Entity\Song;
use Sylament\Tables\Tests\Fixtures\Doctrine\EntityManagerFactory;
use Sylament\Tables\Tests\Fixtures\Doctrine\RecordingMiddleware;

/**
 * Three songs whose titles bracket a shared prefix and whose positions include a
 * null, so the textual collapse and the null exclusion both have something to read.
 */
function rangeRig(Range $summarizer, string $columnName = 'plays', ?RecordingMiddleware $recorder = null, ?Closure $configureTable = null): Range
{
    $entityManager = EntityManagerFactory::create($recorder);

    EntityManagerFactory::seed(
        $entityManager,
        new Song(id: 1, title: 'Airbag', plays: 10, position: 2),
        new Song(id: 2, title: 'Airport', plays: 20),
        new Song(id: 3, title: 'Jóga', plays: 300, position: 7),
    );

    $recorder?->reset();

    $host = new TableHost(function (Table $table) use ($entityManager, $configureTable): Table {
        $table = $table->query($entityManager->createQueryBuilder()->select('song')->from(Song::class, 'song'));

        return $configureTable instanceof Closure ? $configureTable($table) : $table;
    });

    $summarizer
        ->column(TextColumn::make($columnName)->table($host->getTable()))
        ->query($host->getFilteredTableQuery());

    return $summarizer;
}

it('renders through the range partial', function (): void {
    expect(Range::make()->getTemplate())->toBe('@SylamentTables/partials/Summarizer/range.html.twig');
});

it('reads the minimum and the maximum as a pair', function (): void {
    expect(rangeRig(Range::make())->getState())->toBe([10, 300])
        ->and(rangeRig(Range::make(), 'title')->getState())->toBe(['Airbag', 'Jóga']);
});

it('reads an empty set as a pair of nulls', function (): void {
    $summarizer = rangeRig(Range::make());
    $query = $summarizer->getQuery();

    expect($query)->not->toBeNull();
    assert($query instanceof QueryBuilder);

    expect($summarizer->query($query->andWhere('song.id = 999'))->getState())->toBe([null, null]);
});

it('reads both ends in one query', function (): void {
    $recorder = new RecordingMiddleware();
    $summarizer = rangeRig(Range::make(), recorder: $recorder);

    $recorder->reset();
    $summarizer->getState();

    expect($recorder->statements())->toHaveCount(1);
});

/*
 * SQL's own MIN and MAX skip nulls, so the exclusion changes the constraint rather
 * than the figures — and the constraint is what a user's own aggregate over the same
 * scoped query would inherit.
 */
it('excludes nulls by default and keeps them out of the constraint under excludeNull(false)', function (): void {
    $excludingRecorder = new RecordingMiddleware();
    $includingRecorder = new RecordingMiddleware();

    rangeRig(Range::make(), 'position', $excludingRecorder)->getState();
    rangeRig(Range::make()->excludeNull(false), 'position', $includingRecorder)->getState();

    $excluding = $excludingRecorder->statements();
    $including = $includingRecorder->statements();

    expect(Range::make()->shouldExcludeNull())->toBeTrue()
        ->and(Range::make()->excludeNull(false)->shouldExcludeNull())->toBeFalse()
        ->and(Range::make()->excludeNull(fn (): bool => false)->shouldExcludeNull())->toBeFalse()
        ->and($excluding[0] ?? '')->toContain('IS NOT NULL')
        ->and($including[0] ?? '')->not->toContain('IS NOT NULL');
});

it('leaves the query it was pointed at unconstrained', function (): void {
    $summarizer = rangeRig(Range::make(), 'position');
    $query = $summarizer->getQuery();

    $summarizer->getState();

    expect($query?->getDQL())->not->toContain('IS NOT NULL');
});

it('collapses a textual pair to its first differing character', function (): void {
    $summarizer = rangeRig(Range::make()->minimalTextualDifference(), 'title');

    expect($summarizer->formatState(['Airbag', 'Airport']))->toBe(['Airb', 'Airp'])
        ->and($summarizer->formatState(['Alpha', 'Alpine']))->toBe(['Alph', 'Alpi']);
});

it('collapses an identical or half-blank textual pair to one end', function (): void {
    $summarizer = rangeRig(Range::make()->minimalTextualDifference(), 'title');

    expect($summarizer->formatState(['Airbag', 'Airbag']))->toBe(['Airbag'])
        ->and($summarizer->formatState(['Airbag', '']))->toBe(['Airbag'])
        ->and($summarizer->formatState([null, 'Airbag']))->toBe([1 => 'Airbag']);
});

it('reads a prefix of one end as a difference of one more character', function (): void {
    $summarizer = rangeRig(Range::make()->minimalTextualDifference(), 'title');

    expect($summarizer->formatState(['Air', 'Airport']))->toBe(['Air', 'Airp']);
});

/*
 * The datetime preset formats to the day where the two ends fall on different days,
 * to the instant where they do not, and to one value where they are the same instant
 * — the table's own two default formats deciding what each looks like.
 */
it('formats a date range to the day, the instant or one end', function (): void {
    $summarizer = rangeRig(Range::make()->minimalDateTimeDifference(), 'title');

    expect($summarizer->formatState(['2026-09-02 10:00:00', '2026-09-03 11:30:00']))->toBe(['Sep 2, 2026', 'Sep 3, 2026'])
        ->and($summarizer->formatState(['2026-09-02 10:00:00', '2026-09-02 11:30:00']))->toBe(['Sep 2, 2026 10:00:00', 'Sep 2, 2026 11:30:00'])
        ->and($summarizer->formatState(['2026-09-02 10:00:00', '2026-09-02 10:00:00']))->toBe(['Sep 2, 2026 10:00:00']);
});

it('reads the table default formats for the date range', function (): void {
    $summarizer = rangeRig(
        Range::make()->minimalDateTimeDifference(),
        'title',
        configureTable: fn (Table $table): Table => $table->defaultDateDisplayFormat('Y-m-d')->defaultDateTimeDisplayFormat('Y-m-d H:i'),
    );

    expect($summarizer->formatState(['2026-09-02 10:00:00', '2026-09-03 11:30:00']))->toBe(['2026-09-02', '2026-09-03'])
        ->and($summarizer->formatState(['2026-09-02 10:00:00', '2026-09-02 11:30:00']))->toBe(['2026-09-02 10:00', '2026-09-02 11:30']);
});

it('passes a date range it cannot parse or complete through unchanged', function (): void {
    $summarizer = rangeRig(Range::make()->minimalDateTimeDifference(), 'title');

    expect($summarizer->formatState(['not a date', '2026-09-02 10:00:00']))->toBe(['not a date', '2026-09-02 10:00:00'])
        ->and($summarizer->formatState([null, null]))->toBe([])
        ->and($summarizer->formatState(['2026-09-02 10:00:00', null]))->toBe(['2026-09-02 10:00:00']);
});

it('emits no select statement, so it never batches', function (): void {
    expect(Range::make()->getSelectStatements('song.plays'))->toBe([])
        ->and(Range::make()->hasSelectedState())->toBeFalse();
});

/*
 * The preset over a real date column, where the ends are whatever the driver hands
 * back for `MIN`/`MAX` rather than strings a test wrote: DQL types both as strings
 * and converts neither, so the parse is the port's own and the two table default
 * formats decide which of the pair is shown.
 */
it('collapses a date column range to the day or the instant', function (): void {
    $rig = function (DateTimeImmutable ...$releases): Range {
        $entityManager = EntityManagerFactory::create();
        $songs = [];

        foreach ($releases as $index => $releasedAt) {
            $songs[] = new Song(id: $index + 1, title: 'Track ' . ($index + 1), plays: 10, releasedAt: $releasedAt);
        }

        EntityManagerFactory::seed($entityManager, ...$songs);

        $host = new TableHost(fn (Table $table): Table => $table->query(
            $entityManager->createQueryBuilder()->select('song')->from(Song::class, 'song'),
        ));

        return Range::make()
            ->minimalDateTimeDifference()
            ->column(TextColumn::make('releasedAt')->table($host->getTable()))
            ->query($host->getFilteredTableQuery());
    };

    $acrossDays = $rig(new DateTimeImmutable('2026-09-02 10:00:00'), new DateTimeImmutable('2026-09-03 11:30:00'));
    $withinADay = $rig(new DateTimeImmutable('2026-09-02 10:00:00'), new DateTimeImmutable('2026-09-02 11:30:00'));

    expect($acrossDays->formatState($acrossDays->getState()))->toBe(['Sep 2, 2026', 'Sep 3, 2026'])
        ->and($withinADay->formatState($withinADay->getState()))->toBe(['Sep 2, 2026 10:00:00', 'Sep 2, 2026 11:30:00']);
});
