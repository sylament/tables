<?php

declare(strict_types=1);

use Sylament\Tables\Table;
use Sylament\Tables\Tests\Fixtures\Concerns\TableHost;

it('builds the table through the host table() method exactly once', function (): void {
    $configurations = 0;
    $host = new TableHost(function (Table $table) use (&$configurations): Table {
        $configurations++;

        return $table->records(fn (): array => []);
    });

    expect($host->getTable())->toBe($host->getTable())
        ->and($configurations)->toBe(1);
});

it('resolves records from the data source closure exactly once', function (): void {
    $fetches = 0;
    $host = new TableHost(function (Table $table) use (&$fetches): Table {
        $dataSource = function () use (&$fetches): array {
            $fetches++;

            return [['title' => 'Airbag'], ['title' => 'Let Down']];
        };

        return $table->records($dataSource);
    });

    expect($host->getTableRecords())->toBe([['title' => 'Airbag'], ['title' => 'Let Down']])
        ->and($host->getTableRecords())->toHaveCount(2)
        ->and($fetches)->toBe(1);
});

it('accepts a traversable from the data source and reindexes it as a list', function (): void {
    $host = new TableHost(fn (Table $table): Table => $table->records(fn (): iterable => new ArrayIterator(['a' => ['title' => 'Airbag']])));

    expect($host->getTableRecords())->toBe([['title' => 'Airbag']]);
});

it('throws when no records() closure is declared', function (): void {
    $host = new TableHost();

    expect(fn (): array => $host->getTableRecords())
        ->toThrow(LogicException::class, 'must have [records()]');
});

it('throws when the data source returns a non-iterable', function (): void {
    $host = new TableHost(fn (Table $table): Table => $table->records(fn (): string => 'nope'));

    expect(fn (): array => $host->getTableRecords())
        ->toThrow(LogicException::class, 'must return an iterable');
});

it('injects the table into the records closure', function (): void {
    $host = new TableHost(fn (Table $table): Table => $table
        ->emptyStateHeading('Nothing here')
        ->records(fn (Table $table): array => [['heading' => $table->getEmptyStateHeading()]]));

    expect($host->getTableRecords())->toBe([['heading' => 'Nothing here']]);
});
