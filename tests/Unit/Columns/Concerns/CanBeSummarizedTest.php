<?php

declare(strict_types=1);

use Doctrine\ORM\QueryBuilder;
use Sylament\Tables\Columns\Summarizers\Count;
use Sylament\Tables\Columns\Summarizers\Sum;
use Sylament\Tables\Columns\Summarizers\Summarizer;
use Sylament\Tables\Columns\TextColumn;
use Sylament\Tables\Table;
use Sylament\Tables\Tests\Fixtures\Concerns\TableHost;
use Sylament\Tables\Tests\Fixtures\Doctrine\Entity\Song;
use Sylament\Tables\Tests\Fixtures\Doctrine\EntityManagerFactory;

/**
 * A summarized column mounted to a table, which is what a summarizer reads its
 * table and its live component through.
 *
 * @param array<Summarizer>|Summarizer $summarizers
 */
function summarizedColumn(array|Summarizer $summarizers, string $name = 'plays'): TextColumn
{
    return TextColumn::make($name)->summarize($summarizers)->table(Table::make(new TableHost()));
}

function summarizedColumnQuery(): QueryBuilder
{
    return EntityManagerFactory::create()->createQueryBuilder()->select('song')->from(Song::class, 'song');
}

it('takes one summarizer and a list of them', function (): void {
    expect(summarizedColumn(Sum::make())->getSummarizers())->toHaveCount(1)
        ->and(summarizedColumn([Sum::make(), Count::make()])->getSummarizers())->toHaveCount(2)
        ->and(TextColumn::make('plays')->getSummarizers())->toBe([]);
});

it('binds the column to every summarizer it takes', function (): void {
    $column = summarizedColumn([Sum::make(), Count::make()]);

    foreach ($column->getSummarizers() as $summarizer) {
        expect($summarizer->getColumn())->toBe($column);
    }
});

it('keys a summarizer by its id and appends an idless one', function (): void {
    $column = summarizedColumn([Sum::make('total'), Count::make()]);

    expect(array_keys($column->getSummarizers()))->toBe(['total', 0]);
});

it('retrieves a summarizer by id and answers null for an unknown one', function (): void {
    $total = Sum::make('total');
    $column = summarizedColumn([$total, Count::make()]);

    expect($column->getSummarizer('total'))->toBe($total)
        ->and($column->getSummarizer('missing'))->toBeNull();
});

it('replaces a summarizer registered under the same id', function (): void {
    $second = Count::make('total');
    $column = summarizedColumn([Sum::make('total'), $second]);

    expect($column->getSummarizers())->toHaveCount(1)
        ->and($column->getSummarizer('total'))->toBe($second);
});

it('reports whether it is summarized at all', function (): void {
    expect(TextColumn::make('plays')->hasSummary())->toBeFalse()
        ->and(summarizedColumn(Sum::make())->hasSummary())->toBeTrue();
});

/*
 * The re-point is the whole contract between the band and the tier: each render hands
 * every summarizer the query it is summarizing, and a hidden one drops out of that set
 * — which is why the filter only runs when a query is given.
 */
it('re-points every summarizer at the query and filters the hidden ones out', function (): void {
    $query = summarizedColumnQuery();
    $column = summarizedColumn([Sum::make('total'), Count::make('rows')->hidden()]);

    expect($column->getSummarizers())->toHaveCount(2)
        ->and($column->getSummarizers($query))->toHaveCount(1)
        ->and($column->getSummarizer('total')?->getQuery())->toBe($query)
        ->and($column->hasSummary($query))->toBeTrue();
});

it('reports no summary when every summarizer is hidden for the query', function (): void {
    $column = summarizedColumn(Sum::make()->hidden());

    expect($column->hasSummary())->toBeTrue()
        ->and($column->hasSummary(summarizedColumnQuery()))->toBeFalse();
});
