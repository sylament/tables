<?php

declare(strict_types=1);

use Sylament\Tables\Table;
use Sylament\Tables\Tests\Fixtures\Concerns\TableHost;
use Sylament\Tables\Tests\Fixtures\Records\Artist;
use Sylament\Tables\Tests\Fixtures\Records\Song;

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

    expect($host->getTableRecords())->toBe([['title' => 'Airbag', '__key' => '0'], ['title' => 'Let Down', '__key' => '1']])
        ->and($host->getTableRecords())->toHaveCount(2)
        ->and($fetches)->toBe(1);
});

it('keys object records by their readable id, string-cast', function (): void {
    $host = new TableHost(fn (Table $table): Table => $table->records(fn (): array => [
        new Song('Airbag', 'OK Computer', id: 7),
        new Song('Let Down', 'OK Computer', id: 12),
    ]));

    expect(array_keys($host->getTableRecords()))->toBe([7, 12]);
});

it('string-casts an object record key, unwrapping a Stringable id', function (): void {
    $host = new TableHost(fn (Table $table): Table => $table);

    $uuid = new class implements Stringable {
        public function __toString(): string
        {
            return '0198c0de-7f1a-71e3-9c4d-2b6f5a0d1e42';
        }
    };

    $record = new readonly class ($uuid) {
        public function __construct(
            public Stringable $id,
        ) {
        }
    };

    expect($host->getTableRecordKey(new Song('Airbag', 'OK Computer', id: 7)))->toBe('7')
        ->and($host->getTableRecordKey($record))->toBe('0198c0de-7f1a-71e3-9c4d-2b6f5a0d1e42');
});

it('synthesises __key into array records from the source key and string-casts it', function (): void {
    $host = new TableHost(fn (Table $table): Table => $table->records(fn (): ArrayIterator => new ArrayIterator([
        'a' => ['title' => 'Airbag'],
        5 => ['title' => 'Let Down'],
    ])));

    $records = $host->getTableRecords();

    expect(array_keys($records))->toBe(['a', 5])
        ->and($records['a']['__key'])->toBe('a')
        ->and($records[5]['__key'])->toBe('5');
});

it('keeps an explicit __key entry over the source key', function (): void {
    $host = new TableHost(fn (Table $table): Table => $table->records(fn (): array => [
        ['title' => 'Airbag', '__key' => 'song-1'],
    ]));

    expect(array_keys($host->getTableRecords()))->toBe(['song-1']);
});

it('lets a later duplicate key overwrite an earlier record, matching the re-key semantics', function (): void {
    $host = new TableHost(fn (Table $table): Table => $table->records(fn (): array => [
        new Song('Airbag', 'OK Computer', id: 7),
        new Song('Let Down', 'OK Computer', id: 7),
    ]));

    $records = $host->getTableRecords();

    expect($records)->toHaveCount(1)
        ->and($records[7]->title)->toBe('Let Down');
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

it('throws for an object record with no readable id', function (): void {
    $host = new TableHost(fn (Table $table): Table => $table->records(fn (): array => [new Song('Airbag', 'OK Computer')]));

    $host->getTableRecords();
})->throws(LogicException::class, 'readable non-blank [id]');

it('throws for an object record with no id property at all', function (): void {
    $host = new TableHost(fn (Table $table): Table => $table->records(fn (): array => [new Artist('Radiohead')]));

    $host->getTableRecords();
})->throws(LogicException::class, 'readable non-blank [id]');

it('throws when getTableRecordKey meets an array without the key entry', function (): void {
    $host = new TableHost(fn (Table $table): Table => $table);

    $host->getTableRecordKey(['title' => 'Airbag']);
})->throws(LogicException::class, '[__key] entry');

it('injects the table into the records closure', function (): void {
    $host = new TableHost(fn (Table $table): Table => $table
        ->emptyStateHeading('Nothing here')
        ->records(fn (Table $table): array => [['heading' => $table->getEmptyStateHeading()]]));

    expect($host->getTableRecords())->toBe([['heading' => 'Nothing here', '__key' => '0']]);
});

it('resolves a record by key without populating the render cache when cold', function (): void {
    $evaluations = 0;
    $host = new TableHost(function (Table $table) use (&$evaluations): Table {
        return $table->records(function () use (&$evaluations): array {
            $evaluations++;

            return [new Song('Airbag', 'OK Computer', id: 7)];
        });
    });

    expect($host->getTableRecord('7'))->toBeInstanceOf(Song::class)
        ->and($evaluations)->toBe(1);

    $host->getTableRecords();

    expect($evaluations)->toBe(2);
});

it('reads a warm cache without re-evaluating', function (): void {
    $evaluations = 0;
    $host = new TableHost(function (Table $table) use (&$evaluations): Table {
        return $table->records(function () use (&$evaluations): array {
            $evaluations++;

            return [new Song('Airbag', 'OK Computer', id: 7)];
        });
    });

    $host->getTableRecords();

    expect($host->getTableRecord('7'))->toBeInstanceOf(Song::class)
        ->and($evaluations)->toBe(1);
});

it('returns null for a null or unknown key', function (): void {
    $host = new TableHost(fn (Table $table): Table => $table->records(fn (): array => [new Song('Airbag', 'OK Computer', id: 7)]));

    expect($host->getTableRecord(null))->toBeNull()
        ->and($host->getTableRecord('99'))->toBeNull();
});

it('flushes the cached records so the next read re-evaluates', function (): void {
    $evaluations = 0;
    $host = new TableHost(function (Table $table) use (&$evaluations): Table {
        return $table->records(function () use (&$evaluations): array {
            $evaluations++;

            return [new Song('Airbag', 'OK Computer', id: 7)];
        });
    });

    $host->getTableRecords();
    $host->flushCachedTableRecords();
    $host->getTableRecords();

    expect($evaluations)->toBe(2);
});
