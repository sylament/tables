<?php

declare(strict_types=1);

use Sylament\Tables\Columns\Summarizers\Average;
use Sylament\Tables\Columns\Summarizers\Summarizer;
use Sylament\Tables\Columns\TextColumn;
use Sylament\Tables\Table;
use Sylament\Tables\Tests\Fixtures\Concerns\TableHost;
use Sylament\Tables\Tests\Fixtures\Doctrine\Entity\Song;
use Sylament\Tables\Tests\Fixtures\Doctrine\EntityManagerFactory;

/**
 * @param list<int> $plays
 */
function averageRig(Summarizer $summarizer, array $plays): Summarizer
{
    $entityManager = EntityManagerFactory::create();

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

/*
 * An average of integers is a float even when it divides evenly, which is the one
 * place the normalisation must not round the type down to the column's.
 */
it('averages an integer column to a float', function (): void {
    expect(averageRig(Average::make(), [10, 20, 30])->getState())->toBe(20.0)
        ->and(averageRig(Average::make(), [10, 25])->getState())->toBe(17.5);
});

it('averages an empty set to null', function (): void {
    expect(averageRig(Average::make(), [])->getState())->toBeNull();
});

it('labels itself Average by default', function (): void {
    expect(Average::make()->getLabel())->toBe('Average');
});

it('formats its figure as a number by default', function (): void {
    $summarizer = averageRig(Average::make(), [1000, 3000]);

    expect($summarizer->formatState($summarizer->getState()))->toBe('2,000');
});

it('emits one average select statement under its alias', function (): void {
    expect(Average::make()->selectAlias('sy_summary_1')->getSelectStatements('song.plays'))
        ->toBe(['sy_summary_1' => 'AVG(song.plays)']);
});
