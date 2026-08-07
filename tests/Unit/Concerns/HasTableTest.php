<?php

declare(strict_types=1);

use Doctrine\ORM\EntityManager;
use Doctrine\ORM\QueryBuilder;
use Sylament\Support\Pagination\Paginator;
use Sylament\Tables\Columns\TextColumn;
use Sylament\Tables\Enums\PaginationMode;
use Sylament\Tables\Table;
use Sylament\Tables\Tests\Fixtures\Concerns\TableHost;
use Sylament\Tables\Tests\Fixtures\Doctrine\Entity\Artist as DoctrineArtist;
use Sylament\Tables\Tests\Fixtures\Doctrine\Entity\Book;
use Sylament\Tables\Tests\Fixtures\Doctrine\Entity\CompositePair;
use Sylament\Tables\Tests\Fixtures\Doctrine\Entity\Song as DoctrineSong;
use Sylament\Tables\Tests\Fixtures\Doctrine\EntityManagerFactory;
use Sylament\Tables\Tests\Fixtures\Records\Artist;
use Sylament\Tables\Tests\Fixtures\Records\Song;

function sevenSongs(): EntityManager
{
    $entityManager = EntityManagerFactory::create();

    EntityManagerFactory::seed(
        $entityManager,
        new DoctrineSong(1, 'Alpha One', 10),
        new DoctrineSong(2, 'Alpha Two', 20),
        new DoctrineSong(3, 'Beta Three', 30),
        new DoctrineSong(4, 'Beta Four', 40),
        new DoctrineSong(5, 'Beta Five', 50),
        new DoctrineSong(6, 'Beta Six', 60),
        new DoctrineSong(7, 'Beta Seven', 70),
    );

    return $entityManager;
}

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

it('names both lanes when neither is configured', function (): void {
    new TableHost()->getTableRecords();
})->throws(LogicException::class, 'must have [records()] or [query()]');

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

it('resolves the query lane into entities keyed by their identifier', function (): void {
    $entityManager = EntityManagerFactory::create();
    EntityManagerFactory::seed($entityManager, new DoctrineSong(1, 'Alpha', 10), new DoctrineSong(2, 'Beta', 20));
    $host = new TableHost(fn (Table $table): Table => $table
        ->query($entityManager->createQueryBuilder()->select('song')->from(DoctrineSong::class, 'song'))
        ->columns([TextColumn::make('title')]));

    $records = $host->getTableRecords()->getRecords();

    expect(array_keys($records))->toBe([1, 2])
        ->and($records['2'])->toBeInstanceOf(DoctrineSong::class)
        ->and($records['2']->getTitle())->toBe('Beta');
});

it('lets a records closure win over a set query', function (): void {
    $entityManager = EntityManagerFactory::create();
    $queries = 0;
    $host = new TableHost(function (Table $table) use (&$queries, $entityManager): Table {
        return $table
            ->query(function () use (&$queries, $entityManager): QueryBuilder {
                $queries++;

                return $entityManager->createQueryBuilder()->select('song')->from(DoctrineSong::class, 'song');
            })
            ->records(fn (): array => [new DoctrineSong(1, 'Airbag', 10)]);
    });

    $records = $host->getTableRecords();

    expect(array_keys($records))->toBe([1])
        ->and($records[1])->toBeInstanceOf(DoctrineSong::class)
        ->and($queries)->toBe(0);
});

it('answers a null filtered query when a records closure wins over a set query', function (): void {
    $entityManager = EntityManagerFactory::create();
    $host = new TableHost(fn (Table $table): Table => $table
        ->query($entityManager->createQueryBuilder()->select('song')->from(DoctrineSong::class, 'song'))
        ->records(fn (): array => [new DoctrineSong(1, 'Airbag', 10)]));

    expect($host->getFilteredTableQuery())->toBeNull()
        ->and($host->getFilteredSortedTableQuery())->toBeNull();
});

it('keys entities by their real identifier field through the metadata', function (): void {
    $entityManager = EntityManagerFactory::create();
    EntityManagerFactory::seed($entityManager, new Book('sy-001', 'One'), new Book('sy-002', 'Two'));
    $host = new TableHost(fn (Table $table): Table => $table
        ->query($entityManager->createQueryBuilder()->select('book')->from(Book::class, 'book')));

    expect(array_keys($host->getTableRecords()->getRecords()))->toBe(['sy-001', 'sy-002']);
});

it('refuses a composite identifier loudly', function (): void {
    $entityManager = EntityManagerFactory::create();
    EntityManagerFactory::seed($entityManager, new CompositePair(1, 2, 'Pair'));
    $host = new TableHost(fn (Table $table): Table => $table
        ->query($entityManager->createQueryBuilder()->select('pair')->from(CompositePair::class, 'pair')));

    $host->getTableRecords();
})->throws(LogicException::class, 'composite');

it('refuses an entity whose identifier is blank', function (): void {
    $entityManager = EntityManagerFactory::create();
    $host = new TableHost(fn (Table $table): Table => $table
        ->query($entityManager->createQueryBuilder()->select('book')->from(Book::class, 'book')));

    $host->getTableRecordKey(new Book('', 'Blank'));
})->throws(LogicException::class, 'is not a printable value');

it('refuses an entity whose identifier was never initialised', function (): void {
    $entityManager = EntityManagerFactory::create();
    $host = new TableHost(fn (Table $table): Table => $table
        ->query($entityManager->createQueryBuilder()->select('book')->from(Book::class, 'book')));

    $host->getTableRecordKey(new ReflectionClass(Book::class)->newInstanceWithoutConstructor());
})->throws(LogicException::class, 'has none set');

it('falls back to the id convention for a transient object on a query-lane table', function (): void {
    $entityManager = EntityManagerFactory::create();
    $host = new TableHost(fn (Table $table): Table => $table
        ->query($entityManager->createQueryBuilder()->select('song')->from(DoctrineSong::class, 'song')));

    $record = new class {
        public int $id = 7;
    };

    expect($host->getTableRecordKey($record))->toBe('7');
});

it('applies modifyQueryUsing scopes to the resolved records', function (): void {
    $entityManager = EntityManagerFactory::create();
    EntityManagerFactory::seed($entityManager, new DoctrineSong(1, 'Alpha', 10), new DoctrineSong(2, 'Beta', 20));
    $host = new TableHost(fn (Table $table): Table => $table
        ->query($entityManager->createQueryBuilder()->select('song')->from(DoctrineSong::class, 'song'))
        ->modifyQueryUsing(fn (QueryBuilder $query): QueryBuilder => $query->andWhere('song.plays > 10')));

    $records = $host->getTableRecords()->getRecords();

    expect(array_keys($records))->toBe([2])
        ->and($records[2]->getTitle())->toBe('Beta');
});

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

it('resolves a query-lane record fresh, off the cache, every time', function (): void {
    $entityManager = EntityManagerFactory::create();
    EntityManagerFactory::seed($entityManager, new DoctrineSong(1, 'Alpha', 10), new DoctrineSong(2, 'Beta', 20));
    $host = new TableHost(fn (Table $table): Table => $table
        ->query($entityManager->createQueryBuilder()->select('song')->from(DoctrineSong::class, 'song')));

    $host->getTableRecords();

    $entityManager->createQuery(sprintf('DELETE FROM %s song WHERE song.id = 2', DoctrineSong::class))->execute();
    $entityManager->clear();

    expect($host->getTableRecord('2'))->toBeNull()
        ->and(array_keys($host->getTableRecords()->getRecords()))->toBe([1, 2]);
});

it('resolves a record that is not on the current page', function (): void {
    $entityManager = sevenSongs();
    $host = new TableHost(fn (Table $table): Table => $table
        ->query($entityManager->createQueryBuilder()->select('song')->from(DoctrineSong::class, 'song'))
        ->paginated([2]));

    $record = $host->getTableRecord('5');

    expect(array_keys($host->getTableRecords()->getRecords()))->toBe([1, 2])
        ->and($record)->toBeInstanceOf(DoctrineSong::class)
        ->and($record->getTitle())->toBe('Beta Five');
});

it('resolves a record the active search would exclude', function (): void {
    $entityManager = EntityManagerFactory::create();
    EntityManagerFactory::seed($entityManager, new DoctrineSong(1, 'Alpha', 10), new DoctrineSong(2, 'Noise', 20));
    $host = new TableHost(fn (Table $table): Table => $table
        ->query($entityManager->createQueryBuilder()->select('song')->from(DoctrineSong::class, 'song'))
        ->columns([TextColumn::make('title')->searchable()]));

    $host->tableSearch = 'alpha';

    $record = $host->getTableRecord('2');

    expect(array_keys($host->getTableRecords()->getRecords()))->toBe([1])
        ->and($record)->toBeInstanceOf(DoctrineSong::class)
        ->and($record->getTitle())->toBe('Noise');
});

it('resolves a fetch-joined record with its collection whole', function (): void {
    $entityManager = EntityManagerFactory::create();
    $artist = new DoctrineArtist(1, 'Radiohead');
    EntityManagerFactory::seed(
        $entityManager,
        $artist,
        new DoctrineSong(1, 'Airbag', 10, $artist),
        new DoctrineSong(2, 'Let Down', 20, $artist),
        new DoctrineSong(3, 'No Surprises', 30, $artist),
    );
    $host = new TableHost(fn (Table $table): Table => $table
        ->query($entityManager->createQueryBuilder()
            ->select('artist')
            ->from(DoctrineArtist::class, 'artist')
            ->leftJoin('artist.songs', 'songs')
            ->addSelect('songs')));

    $record = $host->getTableRecord('1');

    expect($record)->toBeInstanceOf(DoctrineArtist::class)
        ->and($record->getSongs())->toHaveCount(3);
});

it('applies scopes with the resolving flag during record resolution', function (): void {
    $flags = [];
    $entityManager = EntityManagerFactory::create();
    EntityManagerFactory::seed($entityManager, new DoctrineSong(1, 'Alpha', 10));
    $host = new TableHost(function (Table $table) use ($entityManager, &$flags): Table {
        return $table
            ->query($entityManager->createQueryBuilder()->select('song')->from(DoctrineSong::class, 'song'))
            ->modifyQueryUsing(function (QueryBuilder $query, bool $isResolvingRecord) use (&$flags): void {
                $flags[] = $isResolvingRecord;
            });
    });

    $host->getTableRecords();

    $record = $host->getTableRecord('1');

    expect($flags)->toBe([false, true])
        ->and($record)->toBeInstanceOf(DoctrineSong::class);
});

it('returns null for an unknown key and throws for a bare table', function (): void {
    $entityManager = EntityManagerFactory::create();
    EntityManagerFactory::seed($entityManager, new DoctrineSong(1, 'Alpha', 10));
    $host = new TableHost(fn (Table $table): Table => $table
        ->query($entityManager->createQueryBuilder()->select('song')->from(DoctrineSong::class, 'song')));

    expect($host->getTableRecord('999'))->toBeNull()
        ->and(fn (): object|array|null => new TableHost()->getTableRecord('1'))
        ->toThrow(LogicException::class, 'must have [records()] or [query()]');
});

it('refuses to resolve a record carrying a composite identifier', function (): void {
    $entityManager = EntityManagerFactory::create();
    EntityManagerFactory::seed($entityManager, new CompositePair(1, 2, 'Pair'));
    $host = new TableHost(fn (Table $table): Table => $table
        ->query($entityManager->createQueryBuilder()->select('pair')->from(CompositePair::class, 'pair')));

    $host->getTableRecord('1');
})->throws(LogicException::class, 'composite');

it('answers the total count on every lane that can', function (): void {
    $entityManager = sevenSongs();
    $configure = fn (Table $table): Table => $table
        ->query($entityManager->createQueryBuilder()->select('song')->from(DoctrineSong::class, 'song'))
        ->columns([TextColumn::make('title')->searchable()]);
    $queryLane = new TableHost($configure);
    $searched = new TableHost($configure);
    $arrayLane = new TableHost(fn (Table $table): Table => $table->records(fn (): array => [
        new Song('Airbag', 'OK Computer', id: 7),
        new Song('Let Down', 'OK Computer', id: 12),
    ]));
    $carrierLane = new TableHost(fn (Table $table): Table => $table->records(fn (): Paginator => new Paginator(
        records: [['title' => 'Airbag'], ['title' => 'Let Down']],
        totalRecordCount: 23,
        page: 2,
        recordsPerPage: 2,
    )));
    $simpleCarrierLane = new TableHost(fn (Table $table): Table => $table->records(fn (): Paginator => new Paginator(
        records: [['title' => 'Airbag']],
        totalRecordCount: null,
        page: 1,
        recordsPerPage: 1,
        hasMoreRecords: true,
    )));

    $searched->tableSearch = 'alpha';

    expect($queryLane->getAllTableRecordsCount())->toBe(7)
        ->and($searched->getAllTableRecordsCount())->toBe(2)
        ->and($arrayLane->getAllTableRecordsCount())->toBe(2)
        ->and($carrierLane->getAllTableRecordsCount())->toBe(23)
        ->and(fn (): int => $simpleCarrierLane->getAllTableRecordsCount())
        ->toThrow(LogicException::class, 'carries no total');
});

it('answers a query-lane count from the rendered total until the cache is flushed', function (): void {
    $entityManager = sevenSongs();
    $host = new TableHost(fn (Table $table): Table => $table
        ->query($entityManager->createQueryBuilder()->select('song')->from(DoctrineSong::class, 'song'))
        ->paginated([2]));

    $host->getTableRecords();

    $entityManager->createQuery(sprintf('DELETE FROM %s song WHERE song.id = 7', DoctrineSong::class))->execute();
    $entityManager->clear();

    expect($host->getAllTableRecordsCount())->toBe(7);

    $host->flushCachedTableRecords();

    expect($host->getAllTableRecordsCount())->toBe(6);
});

it('counts the whole query lane when the rendered page carries no total', function (): void {
    $entityManager = sevenSongs();
    $host = new TableHost(fn (Table $table): Table => $table
        ->query($entityManager->createQueryBuilder()->select('song')->from(DoctrineSong::class, 'song'))
        ->paginated([2])
        ->paginationMode(PaginationMode::Simple));

    $page = $host->getTableRecords();

    expect($page->getTotalRecordCount())->toBeNull()
        ->and($host->getAllTableRecordsCount())->toBe(7);
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

it('cycles sortTable through asc, desc, unsorted on the same column', function (): void {
    $host = new TableHost(fn (Table $t): Table => $t->columns([TextColumn::make('title')->sortable()]));

    $host->sortTable('title');
    expect($host->tableSort)->toBe('title:asc')
        ->and($host->getTableSortColumn())->toBe('title')
        ->and($host->getTableSortDirection())->toBe('asc');

    $host->sortTable('title');
    expect($host->tableSort)->toBe('title:desc');

    $host->sortTable('title');
    expect($host->tableSort)->toBeNull()
        ->and($host->getTableSortColumn())->toBeNull()
        ->and($host->getTableSortDirection())->toBeNull();
});

it('starts a newly sorted column ascending and honours an explicit direction', function (): void {
    $host = new TableHost(fn (Table $t): Table => $t->columns([
        TextColumn::make('title')->sortable(),
        TextColumn::make('status')->sortable(),
    ]));

    $host->sortTable('title');
    $host->sortTable('status');

    expect($host->tableSort)->toBe('status:asc');

    $host->sortTable('title', 'desc');
    expect($host->tableSort)->toBe('title:desc');
});

it('ignores a sort on an unknown, unsortable, or invalid-direction request', function (): void {
    $host = new TableHost(fn (Table $t): Table => $t->columns([
        TextColumn::make('title')->sortable(),
        TextColumn::make('slug'),
    ]));

    $host->sortTable('slug');
    $host->sortTable('missing');
    $host->sortTable();
    $host->sortTable('title', 'sideways');

    expect($host->tableSort)->toBeNull();
});

it('string-casts a numeric sort column argument', function (): void {
    $host = new TableHost(fn (Table $t): Table => $t->columns([TextColumn::make('2024')->sortable()]));

    $host->sortTable(2024);

    expect($host->tableSort)->toBe('2024:asc');
});

it('parses a bare column as ascending and garbage direction as null', function (): void {
    $host = new TableHost();
    $host->tableSort = 'title';

    expect($host->getTableSortColumn())->toBe('title')
        ->and($host->getTableSortDirection())->toBe('asc');

    $host->tableSort = 'title:bogus';

    expect($host->getTableSortDirection())->toBeNull();
});

it('resets to the first page when sort, per-page, or search change', function (): void {
    $host = new TableHost(fn (Table $t): Table => $t->columns([TextColumn::make('title')->sortable()]));
    $host->gotoTablePage(4);

    expect($host->getTablePage())->toBe(4);

    $host->sortTable('title');
    expect($host->getTablePage())->toBe(1);

    $host->gotoTablePage(4);
    $host->tableRecordsPerPage = '25';
    $host->updatedTableRecordsPerPage('10');

    expect($host->getTablePage())->toBe(1);

    $host->gotoTablePage(4);
    $host->tableSearch = 'radiohead';
    $host->updatedTableSearch('');

    expect($host->getTablePage())->toBe(1);
});

it('clamps gotoTablePage to one', function (): void {
    $host = new TableHost();
    $host->gotoTablePage(-3);

    expect($host->getTablePage())->toBe(1);
});

it('seeds records per page from the table default without writing the prop', function (): void {
    $host = new TableHost();

    expect($host->getTableRecordsPerPage())->toBe(10)
        ->and($host->tableRecordsPerPage)->toBeNull();
});

it('reports null records per page on an unpaginated table', function (): void {
    $host = new TableHost(fn (Table $t): Table => $t->paginated(false));

    expect($host->getTableRecordsPerPage())->toBeNull();
});

it('normalizes the per-page prop and falls back to the default outside the options', function (): void {
    $host = new TableHost(fn (Table $t): Table => $t->paginationPageOptions([5, 10, 'all']));

    $host->tableRecordsPerPage = '5';

    expect($host->getTableRecordsPerPage())->toBe(5);

    $host->tableRecordsPerPage = 'all';
    expect($host->getTableRecordsPerPage())->toBe('all');

    $host->tableRecordsPerPage = '999999';
    expect($host->getTableRecordsPerPage())->toBe(10);

    $host->tableRecordsPerPage = 'drop table';
    expect($host->getTableRecordsPerPage())->toBe(10);
});

it('trims the search and reads blank as null', function (): void {
    $host = new TableHost();

    expect($host->getTableSearch())->toBeNull();

    $host->tableSearch = "  ok computer\u{00A0}";
    expect($host->getTableSearch())->toBe('ok computer');

    $host->tableSearch = "\u{00A0}\u{2003}";
    expect($host->getTableSearch())->toBeNull();
});

it('dehydrates the per-page prop as the resolved option, string-cast', function (): void {
    $seeded = new TableHost();
    $all = new TableHost(fn (Table $t): Table => $t->paginationPageOptions([5, 'all']));
    $optionless = new TableHost(fn (Table $t): Table => $t->paginationPageOptions([]));

    $all->tableRecordsPerPage = 'all';

    expect($seeded->dehydrateTableRecordsPerPage($seeded->getTableRecordsPerPage()))->toBe('10')
        ->and($all->dehydrateTableRecordsPerPage($all->getTableRecordsPerPage()))->toBe('all')
        ->and($optionless->dehydrateTableRecordsPerPage($optionless->getTableRecordsPerPage()))->toBeNull();
});

it('dehydrates the search prop raw, where the getter trims and blanks it', function (): void {
    $host = new TableHost();

    expect($host->dehydrateTableSearch($host->getTableSearch()))->toBe('')
        ->and($host->getTableSearch())->toBeNull();

    $host->tableSearch = '  ok computer ';

    expect($host->dehydrateTableSearch($host->getTableSearch()))->toBe('  ok computer ')
        ->and($host->getTableSearch())->toBe('ok computer');
});

it('ignores an echoed per-page update and resets the page on a real change', function (): void {
    $fetches = 0;
    $host = new TableHost(function (Table $table) use (&$fetches): Table {
        return $table->records(function () use (&$fetches): array {
            $fetches++;

            return [];
        });
    });

    $host->gotoTablePage(4);
    $host->getTableRecords();

    $host->tableRecordsPerPage = '10';
    $host->updatedTableRecordsPerPage('10');
    $host->getTableRecords();

    expect($host->getTablePage())->toBe(4)
        ->and($fetches)->toBe(1);

    $host->tableRecordsPerPage = '25';
    $host->updatedTableRecordsPerPage('10');
    $host->getTableRecords();

    expect($host->getTablePage())->toBe(1)
        ->and($fetches)->toBe(2);
});

it('ignores an echoed search update and resets the page on a real change', function (): void {
    $fetches = 0;
    $host = new TableHost(function (Table $table) use (&$fetches): Table {
        return $table->records(function () use (&$fetches): array {
            $fetches++;

            return [];
        });
    });

    $host->gotoTablePage(4);
    $host->getTableRecords();

    $host->updatedTableSearch('');
    $host->getTableRecords();

    expect($host->getTablePage())->toBe(4)
        ->and($fetches)->toBe(1);

    $host->tableSearch = 'radiohead';
    $host->updatedTableSearch('');
    $host->getTableRecords();

    expect($host->getTablePage())->toBe(1)
        ->and($fetches)->toBe(2);
});

it('injects the table state into the records closure lazily', function (): void {
    $seen = null;
    $host = new TableHost(function (Table $t) use (&$seen): Table {
        return $t
            ->columns([TextColumn::make('title')->sortable()])
            ->records(function (int $page, int|string|null $recordsPerPage, ?string $search, array $sort, ?string $sortColumn, ?string $sortDirection) use (&$seen): array {
                $seen = ['page' => $page, 'recordsPerPage' => $recordsPerPage, 'search' => $search, 'sort' => $sort, 'sortColumn' => $sortColumn, 'sortDirection' => $sortDirection];

                return [];
            });
    });

    $host->sortTable('title', 'desc');
    $host->gotoTablePage(3);
    $host->tableSearch = 'radiohead';
    $host->getTableRecords();

    expect($seen)->toBe([
        'page' => 3,
        'recordsPerPage' => 10,
        'search' => 'radiohead',
        'sort' => ['title', 'desc'],
        'sortColumn' => 'title',
        'sortDirection' => 'desc',
    ]);
});

it('re-resolves after a sort, page, or search mutation flushes the cache', function (): void {
    $fetches = 0;
    $host = new TableHost(function (Table $t) use (&$fetches): Table {
        return $t
            ->columns([TextColumn::make('title')->sortable()])
            ->records(function () use (&$fetches): array {
                $fetches++;

                return [];
            });
    });

    $host->getTableRecords();
    $host->sortTable('title');
    $host->getTableRecords();

    expect($fetches)->toBe(2);

    $host->gotoTablePage(3);
    $host->getTableRecords();

    expect($fetches)->toBe(3);

    $host->tableSearch = 'radiohead';
    $host->updatedTableSearch();
    $host->getTableRecords();

    expect($fetches)->toBe(4);
});

it('re-keys a returned Paginator by record key and caches the clone', function (): void {
    $host = new TableHost(fn (Table $t): Table => $t->records(fn (): Paginator => new Paginator(
        records: [['title' => 'Airbag'], ['title' => 'Let Down']],
        totalRecordCount: 23,
        page: 2,
        recordsPerPage: 2,
    )));

    $records = $host->getTableRecords();

    expect($records)->toBeInstanceOf(Paginator::class)
        ->and($records->getRecords())->toBe([
            ['title' => 'Airbag', '__key' => '0'],
            ['title' => 'Let Down', '__key' => '1'],
        ])
        ->and($records->getTotalRecordCount())->toBe(23)
        ->and($host->getTableRecords())->toBe($records)
        ->and($host->getTablePaginator())->toBe($records)
        ->and($host->getTableRecord('1'))->toBe(['title' => 'Let Down', '__key' => '1']);
});

it('reports a null paginator for a plain iterable return', function (): void {
    $host = new TableHost(fn (Table $t): Table => $t->records(fn (): array => [['title' => 'Airbag']]));

    expect($host->getTablePaginator())->toBeNull()
        ->and($host->getTable()->getPaginator())->toBeNull();
});

it('exposes the paginator through the table delegates', function (): void {
    $host = new TableHost(fn (Table $t): Table => $t
        ->columns([TextColumn::make('title')->sortable()])
        ->records(fn (): Paginator => new Paginator([], 0, 1, 5)));

    $host->sortTable('title');

    expect($host->getTable()->getPaginator())->toBeInstanceOf(Paginator::class)
        ->and($host->getTable()->getSortColumn())->toBe('title')
        ->and($host->getTable()->getSortDirection())->toBe('asc');
});

it('names the Paginator arm when the closure returns garbage', function (): void {
    $host = new TableHost(fn (Table $t): Table => $t->records(fn (): int => 42));

    expect(fn (): array|Paginator => $host->getTableRecords())
        ->toThrow(LogicException::class, 'Paginator');
});
