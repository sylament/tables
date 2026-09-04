<?php

declare(strict_types=1);

use Sylament\Tables\Columns\Summarizers\Sum;
use Sylament\Tables\Columns\Summarizers\Summarizer;
use Sylament\Tables\Columns\TextColumn;
use Sylament\Tables\Table;
use Sylament\Tables\Tests\Fixtures\Concerns\TableHost;
use Sylament\Tables\Tests\Fixtures\Doctrine\Entity\Song;
use Sylament\Tables\Tests\Fixtures\Doctrine\EntityManagerFactory;
use Sylament\Tables\Tests\Fixtures\Doctrine\RecordingMiddleware;

/**
 * The summarizer under test, pointed at a songs query carrying the plays given —
 * over a table naming an explicit number locale, so the formatted figures do not
 * move with the machine's.
 *
 * @param list<int> $plays
 */
function sumRig(Summarizer $summarizer, array $plays, ?RecordingMiddleware $recorder = null): Summarizer
{
    $entityManager = EntityManagerFactory::create($recorder);

    EntityManagerFactory::seed($entityManager, ...array_map(
        static fn (int $count, int $index): Song => new Song(id: $index + 1, title: sprintf('Song %d', $index + 1), plays: $count),
        $plays,
        array_keys($plays),
    ));

    $host = new TableHost(fn (Table $table): Table => $table->query(
        $entityManager->createQueryBuilder()->select('song')->from(Song::class, 'song'),
    ));

    return $summarizer
        ->column(TextColumn::make('plays')->table(Table::make($host)->defaultNumberLocale('en_US')))
        ->query($host->getFilteredTableQuery());
}

it('sums an integer column over the query', function (): void {
    expect(sumRig(Sum::make(), [10, 20, 300])->getState())->toBe(330);
});

/*
 * SQL sums no rows to null rather than to zero, and the port keeps that answer: a
 * placeholder is the summary row's way of saying so, not a zero the data never had.
 */
it('sums an empty set to null', function (): void {
    expect(sumRig(Sum::make(), [])->getState())->toBeNull();
});

it('labels itself Sum by default', function (): void {
    expect(Sum::make()->getDefaultLabel())->toBe('Sum')
        ->and(Sum::make()->getLabel())->toBe('Sum')
        ->and(Sum::make()->label('Total')->getLabel())->toBe('Total');
});

it('formats its figure as a number by default', function (): void {
    $summarizer = sumRig(Sum::make(), [1000, 2000]);

    expect($summarizer->formatState($summarizer->getState()))->toBe('3,000');
});

/*
 * R1: the money formatter reaches the summary row over a real aggregate, which is
 * where the driver's own value type — a number here, a numeric string on PostgreSQL —
 * meets the intl formatter.
 */
it('formats a summed figure as money', function (): void {
    $summarizer = sumRig(Sum::make()->money(), [1000, 234]);

    expect($summarizer->formatState($summarizer->getState()))->toBe('$1,234.00');
});

it('emits one sum select statement under its alias', function (): void {
    $summarizer = Sum::make()->selectAlias('sy_summary_0');

    expect($summarizer->getSelectAlias())->toBe('sy_summary_0')
        ->and($summarizer->getSelectStatements('song.plays'))->toBe(['sy_summary_0' => 'SUM(song.plays)']);
});

it('holds no selected state before an alias has been pushed onto it', function (): void {
    $summarizer = Sum::make()->selectedState(['sy_summary_0' => 330]);

    expect($summarizer->hasSelectedState())->toBeFalse()
        ->and($summarizer->getSelectedState())->toBeNull();
});

/*
 * The whole point of the batch: a summarizer whose alias the batched row carries
 * answers from it and runs no query of its own.
 */
it('answers a batched figure without querying', function (): void {
    $recorder = new RecordingMiddleware();
    $summarizer = sumRig(Sum::make(), [10, 20], $recorder)
        ->selectAlias('sy_summary_0')
        ->selectedState(['sy_summary_0' => 999]);

    $recorder->reset();

    expect($summarizer->hasSelectedState())->toBeTrue()
        ->and($summarizer->getState())->toBe(999)
        ->and($recorder->statements())->toBe([]);
});

/*
 * D29: DQL types a sum as a string and converts nothing, so PostgreSQL hands back
 * "330" where SQLite hands back 330 — normalised once, at this boundary.
 */
it('normalises a driver string to an int or a float', function (): void {
    $summarizer = Sum::make()->selectAlias('sy_summary_0');

    expect($summarizer->selectedState(['sy_summary_0' => '330'])->getSelectedState())->toBe(330)
        ->and($summarizer->selectedState(['sy_summary_0' => '82.5000000000000000'])->getSelectedState())->toBe(82.5)
        ->and($summarizer->selectedState(['sy_summary_0' => null])->getSelectedState())->toBeNull();
});

/*
 * D27: the row carrying the alias at all is what says a figure was read, because SQL
 * aggregates an empty set — an empty page, a fully filtered table — to null, and that
 * null is an answer. Asking whether the value is filled instead would send every such
 * figure back to the database for the same null, and on the records lane into the
 * refusal getState() makes for a table no query produced.
 */
it('answers a batched null without querying', function (): void {
    $recorder = new RecordingMiddleware();
    $summarizer = sumRig(Sum::make(), [10, 20], $recorder)
        ->selectAlias('sy_summary_0')
        ->selectedState(['sy_summary_0' => null]);

    $recorder->reset();

    expect($summarizer->hasSelectedState())->toBeTrue()
        ->and($summarizer->getState())->toBeNull()
        ->and($recorder->statements())->toBe([]);
});
