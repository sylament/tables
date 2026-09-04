<?php

declare(strict_types=1);

use Doctrine\ORM\QueryBuilder;
use Sylament\Tables\Columns\Column;
use Sylament\Tables\Columns\Summarizers\Sum;
use Sylament\Tables\Columns\TextColumn;
use Sylament\Tables\Table;
use Sylament\Tables\Tests\Fixtures\Concerns\TableHost;
use Sylament\Tables\Tests\Fixtures\Doctrine\Entity\Song;
use Sylament\Tables\Tests\Fixtures\Doctrine\EntityManagerFactory;

/**
 * @param array<Column> $columns
 */
function summarizedTable(array $columns = []): Table
{
    return new TableHost(fn (Table $table): Table => $table
        ->query(EntityManagerFactory::create()->createQueryBuilder()->select('song')->from(Song::class, 'song'))
        ->columns($columns))->getTable();
}

function summarizedTableQuery(Table $table): QueryBuilder
{
    $query = $table->getQuery();

    expect($query)->toBeInstanceOf(QueryBuilder::class);
    assert($query instanceof QueryBuilder);

    return $query;
}

it('renders both summary rows by default', function (): void {
    $table = summarizedTable();

    expect($table->hasPageSummary())->toBeTrue()
        ->and($table->hasAllTableSummary())->toBeTrue();
});

it('turns each summary row off independently', function (bool $page, bool $all): void {
    $table = summarizedTable()->summaries($page, $all);

    expect($table->hasPageSummary())->toBe($page)
        ->and($table->hasAllTableSummary())->toBe($all);
})->with([
    'neither' => [false, false],
    'the page row alone' => [true, false],
    'the all-table row alone' => [false, true],
]);

it('evaluates both conditions as closures', function (): void {
    $table = summarizedTable()->summaries(fn (): bool => false, fn (): bool => true);

    expect($table->hasPageSummary())->toBeFalse()
        ->and($table->hasAllTableSummary())->toBeTrue();
});

it('reports a summary when any column carries a visible summarizer', function (): void {
    $table = summarizedTable([TextColumn::make('title'), TextColumn::make('plays')->summarize(Sum::make())]);

    expect($table->hasSummary(summarizedTableQuery($table)))->toBeTrue()
        ->and($table->hasSummary(null))->toBeTrue();
});

it('reports no summary when no column is summarized', function (): void {
    $table = summarizedTable([TextColumn::make('title')]);

    expect($table->hasSummary(summarizedTableQuery($table)))->toBeFalse();
});

it('reports no summary when every summarizer is hidden for the query', function (): void {
    $table = summarizedTable([TextColumn::make('plays')->summarize(Sum::make()->hidden())]);

    expect($table->hasSummary(summarizedTableQuery($table)))->toBeFalse();
});

/*
 * The band renders the visible columns while this gate reads every declared one, which
 * is vendor's own asymmetry: a column the user toggled off still makes the table report
 * a summary, so the rows are rendered and its cell is simply not among them.
 */
it('reads every declared column, not only the visible ones', function (): void {
    $table = summarizedTable([
        TextColumn::make('title'),
        TextColumn::make('plays')->summarize(Sum::make())->hidden(),
    ]);

    expect($table->getVisibleColumns())->toHaveCount(1)
        ->and($table->hasSummary(summarizedTableQuery($table)))->toBeTrue();
});
