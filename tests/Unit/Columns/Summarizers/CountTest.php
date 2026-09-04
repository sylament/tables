<?php

declare(strict_types=1);

use Sylament\Tables\Columns\Column;
use Sylament\Tables\Columns\IconColumn;
use Sylament\Tables\Columns\Summarizers\Count;
use Sylament\Tables\Columns\Summarizers\Summarizer;
use Sylament\Tables\Columns\TextColumn;
use Sylament\Tables\Table;
use Sylament\Tables\Tests\Fixtures\Concerns\TableHost;
use Sylament\Tables\Tests\Fixtures\Doctrine\Entity\Song;
use Sylament\Tables\Tests\Fixtures\Doctrine\EntityManagerFactory;

/**
 * @param list<int> $plays
 */
function countRig(Summarizer $summarizer, array $plays, ?Column $column = null): Summarizer
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
        ->column(($column ?? TextColumn::make('plays'))->table(Table::make($host)->defaultNumberLocale('en_US')))
        ->query($host->getFilteredTableQuery());
}

it('counts the rows the query reads', function (): void {
    expect(countRig(Count::make(), [10, 20, 300])->getState())->toBe(3);
});

/*
 * A count of nothing is zero rather than null, which is what tells it apart from the
 * other four over an empty set.
 */
it('counts an empty set to zero', function (): void {
    expect(countRig(Count::make(), [])->getState())->toBe(0);
});

it('labels itself Count by default, and nothing under icons()', function (): void {
    expect(Count::make()->getLabel())->toBe('Count')
        ->and(Count::make()->icons()->getLabel())->toBeNull()
        ->and(Count::make()->hasIcons())->toBeFalse()
        ->and(Count::make()->icons()->hasIcons())->toBeTrue();
});

it('emits one count select statement under its alias, and none under icons()', function (): void {
    expect(Count::make()->selectAlias('sy_summary_2')->getSelectStatements('song.plays'))
        ->toBe(['sy_summary_2' => 'COUNT(song.plays)'])
        ->and(Count::make()->selectAlias('sy_summary_2')->icons()->getSelectStatements('song.plays'))
        ->toBe([]);
});

/*
 * The icon count's API ships so a migrating user finds it, but the summary itself
 * waits for a per-icon count over an IconColumn — a feature rather than a copy — and
 * refuses rather than rendering a plain number under an icon column's heading.
 */
it('refuses to summarize under icons(), naming the column type it waits on', function (): void {
    $summarizer = countRig(Count::make()->icons(), [10, 20], IconColumn::make('isFeatured'));

    expect(fn (): mixed => $summarizer->getState())
        ->toThrow(LogicException::class, 'IconColumn');
});
