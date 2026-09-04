<?php

declare(strict_types=1);

use Doctrine\ORM\EntityManager;
use Sylament\Tables\Columns\Summarizers\Sum;
use Sylament\Tables\Columns\TextColumn;
use Sylament\Tables\Grouping\Group;
use Sylament\Tables\Table;
use Sylament\Tables\Tests\Fixtures\Concerns\TableHost;
use Sylament\Tables\Tests\Fixtures\Doctrine\Entity\Artist;
use Sylament\Tables\Tests\Fixtures\Doctrine\Entity\Song;
use Sylament\Tables\Tests\Fixtures\Doctrine\EntityManagerFactory;
use Sylament\Tables\Tests\Fixtures\Doctrine\RecordingMiddleware;

/*
 * The host half of grouping: which group the property resolves to, what the
 * direction reads as, what the pipeline does with both, and the boundary map the
 * partial renders group headers from. The definition half — which groups exist —
 * stays in `Unit/Table/Concerns/CanGroupRecordsTest.php`.
 */

/**
 * Six songs over two artists and one orphan, so a page carries more than one
 * group and one record whose group key is blank.
 */
function groupedSongsFixture(?RecordingMiddleware $recorder = null): EntityManager
{
    $entityManager = EntityManagerFactory::create($recorder);

    $radiohead = new Artist(1, 'Radiohead');
    $portishead = new Artist(2, 'Portishead');

    EntityManagerFactory::seed(
        $entityManager,
        $radiohead,
        $portishead,
        new Song(1, 'Airbag', 100, $radiohead),
        new Song(2, 'Exit Music', 100, $radiohead),
        new Song(3, 'Glory Box', 200, $portishead),
        new Song(4, 'Roads', 200, $portishead),
        new Song(5, 'Untitled', 300),
    );

    return $entityManager;
}

/**
 * @param (Closure(Table): Table)|null $configure
 */
function groupedSongsHost(EntityManager $entityManager, ?Closure $configure = null): TableHost
{
    return new TableHost(function (Table $table) use ($entityManager, $configure): Table {
        $table
            ->query($entityManager->createQueryBuilder()->select('song')->from(Song::class, 'song'))
            ->columns([TextColumn::make('title')->sortable(), TextColumn::make('plays')->sortable()])
            ->groups(['plays', 'artist.name']);

        return $configure instanceof Closure ? $configure($table) : $table;
    });
}

it('resolves the grouping named by the prop', function (): void {
    $host = groupedSongsHost(groupedSongsFixture());
    $host->tableGrouping = 'plays';

    expect($host->getTableGrouping())->toBe($host->getTable()->getGroup('plays'));
});

it('splits an id:direction prop and resolves the id half', function (): void {
    $host = groupedSongsHost(groupedSongsFixture());
    $host->tableGrouping = 'artist.name:desc';

    expect($host->getTableGroupingColumn())->toBe('artist.name')
        ->and($host->getTableGrouping())->toBe($host->getTable()->getGroup('artist.name'));
});

it('falls back for a prop naming no registered group', function (): void {
    $bare = groupedSongsHost(groupedSongsFixture());
    $bare->tableGrouping = 'nope';

    $defaulted = groupedSongsHost(groupedSongsFixture(), fn (Table $table): Table => $table->defaultGroup('title'));
    $defaulted->tableGrouping = 'nope';

    expect($bare->getTableGrouping())->toBeNull()
        ->and($defaulted->getTableGrouping()?->getId())->toBe('title');
});

it('answers the default group when the prop is blank', function (): void {
    $host = groupedSongsHost(groupedSongsFixture(), fn (Table $table): Table => $table->defaultGroup('title'));

    expect($host->getTableGrouping()?->getId())->toBe('title');
});

it('answers null rather than the default when the default is itself selectable', function (): void {
    $host = groupedSongsHost(groupedSongsFixture(), fn (Table $table): Table => $table->defaultGroup('plays'));

    expect($host->getTable()->isDefaultGroupSelectable())->toBeTrue()
        ->and($host->getTableGrouping())->toBeNull();
});

it('answers null while reordering, whatever the prop says', function (): void {
    $host = groupedSongsHost(groupedSongsFixture(), fn (Table $table): Table => $table->reorderable('position'));
    $host->tableGrouping = 'plays';
    $host->toggleTableReordering();

    expect($host->isTableReordering())->toBeTrue()
        ->and($host->getTableGrouping())->toBeNull();
});

it('reads no direction from a blank prop', function (): void {
    $host = groupedSongsHost(groupedSongsFixture());

    expect($host->getTableGroupingDirection())->toBeNull()
        ->and($host->getTableGroupingColumn())->toBeNull();
});

it('defaults an undecorated prop to asc', function (): void {
    $host = groupedSongsHost(groupedSongsFixture());
    $host->tableGrouping = 'plays';

    expect($host->getTableGroupingDirection())->toBe('asc');
});

it('reads asc and desc off the prop', function (string $direction): void {
    $host = groupedSongsHost(groupedSongsFixture());
    $host->tableGrouping = 'plays:' . $direction;

    expect($host->getTableGroupingDirection())->toBe($direction);
})->with(['asc', 'desc']);

it('refuses a direction that is not one', function (): void {
    $host = groupedSongsHost(groupedSongsFixture());
    $host->tableGrouping = 'plays:sideways';

    expect($host->getTableGroupingDirection())->toBeNull();
});

it('adds the grouping order before the sort', function (): void {
    $host = groupedSongsHost(groupedSongsFixture());
    $host->tableGrouping = 'plays:desc';
    $host->sortTable('title');

    expect($host->getFilteredSortedTableQuery()?->getDQL())
        ->toContain('ORDER BY song.plays desc, song.title asc, song.id asc');
});

it('adds no ordering of its own when nothing is grouped', function (): void {
    $host = groupedSongsHost(groupedSongsFixture());
    $host->sortTable('title');

    expect($host->getFilteredSortedTableQuery()?->getDQL())
        ->toContain('ORDER BY song.title asc, song.id asc')
        ->and($host->getFilteredSortedTableQuery()?->getDQL())->not->toContain('song.plays');
});

it('lets a reorder replace the grouping order', function (): void {
    $host = groupedSongsHost(groupedSongsFixture(), fn (Table $table): Table => $table->reorderable('position'));
    $host->tableGrouping = 'plays:desc';
    $host->sortTable('title');
    $host->toggleTableReordering();

    expect($host->getFilteredSortedTableQuery()?->getDQL())->toContain('ORDER BY song.position asc')
        ->and($host->getFilteredSortedTableQuery()?->getDQL())->not->toContain('song.plays')
        ->and($host->getFilteredSortedTableQuery()?->getDQL())->not->toContain('song.title');
});

it('eager loads the grouped relation once', function (): void {
    $host = groupedSongsHost(groupedSongsFixture());
    $host->tableGrouping = 'artist.name';

    $dql = $host->getFilteredSortedTableQuery()?->getDQL() ?? '';

    expect($dql)->toContain('LEFT JOIN song.artist sy_artist', 'SELECT song, sy_artist')
        ->and(mb_substr_count($dql, 'LEFT JOIN song.artist'))->toBe(1)
        ->and($dql)->toContain('ORDER BY sy_artist.name asc');
});

it('groups a relation path and orders the rows by it', function (): void {
    $ascending = groupedSongsHost(groupedSongsFixture());
    $ascending->tableGrouping = 'artist.name';

    $descending = groupedSongsHost(groupedSongsFixture());
    $descending->tableGrouping = 'artist.name:desc';

    // The orphan sorts with the nulls, which SQLite puts first ascending and
    // last descending — so neither order is the identifier's own.
    expect(array_keys($ascending->getTableRecords()->getRecords()))->toBe([5, 3, 4, 1, 2])
        ->and(array_keys($descending->getTableRecords()->getRecords()))->toBe([1, 2, 3, 4, 5]);
});

it('resets the page and flushes the records when the grouping changes', function (): void {
    $host = groupedSongsHost(groupedSongsFixture(), fn (Table $table): Table => $table->paginated([2]));
    $host->gotoTablePage(3);

    expect($host->getTablePage())->toBe(3);

    $host->getTableRecords();
    $host->groupTable('plays');

    expect($host->tableGrouping)->toBe('plays')
        ->and($host->getTablePage())->toBe(1)
        ->and(array_keys($host->getTableRecords()->getRecords()))->toBe([1, 2]);
});

it('writes a descending grouping as an id:desc pair and a bare id otherwise', function (): void {
    $host = groupedSongsHost(groupedSongsFixture());

    $host->groupTable('plays', 'desc');

    expect($host->tableGrouping)->toBe('plays:desc');

    $host->groupTable('plays', 'asc');

    expect($host->tableGrouping)->toBe('plays');
});

it('clears the grouping for a blank group', function (): void {
    $host = groupedSongsHost(groupedSongsFixture());
    $host->tableGrouping = 'plays';

    $host->groupTable('');

    expect($host->tableGrouping)->toBeNull()
        ->and($host->getTablePage())->toBe(1);
});

it('refuses a grouping write naming a group the table does not offer', function (): void {
    $host = groupedSongsHost(groupedSongsFixture());
    $host->tableGrouping = 'plays';

    $host->groupTable('title');

    expect($host->tableGrouping)->toBe('plays');
});

it('refuses a grouping write whose direction is not one', function (): void {
    $host = groupedSongsHost(groupedSongsFixture());

    $host->groupTable('plays', 'sideways');

    expect($host->tableGrouping)->toBeNull();
});

it('clears a seeded grouping the table does not offer at mount', function (): void {
    $host = groupedSongsHost(groupedSongsFixture());
    $host->tableGrouping = 'nope';

    $host->mountTableGrouping();

    expect($host->tableGrouping)->toBeNull();
});

it('clears a seeded grouping whose direction is not one at mount', function (): void {
    $host = groupedSongsHost(groupedSongsFixture());
    $host->tableGrouping = 'plays:sideways';

    $host->mountTableGrouping();

    expect($host->tableGrouping)->toBeNull();
});

it('seeds a selectable default group as a bare id at mount', function (): void {
    $selectable = groupedSongsHost(groupedSongsFixture(), fn (Table $table): Table => $table->defaultGroup('plays'));
    $unselectable = groupedSongsHost(groupedSongsFixture(), fn (Table $table): Table => $table->defaultGroup('title'));
    $none = groupedSongsHost(groupedSongsFixture());

    $selectable->mountTableGrouping();
    $unselectable->mountTableGrouping();
    $none->mountTableGrouping();

    expect($selectable->tableGrouping)->toBe('plays')
        ->and($unselectable->tableGrouping)->toBeNull()
        ->and($none->tableGrouping)->toBeNull();
});

it('leaves a seeded grouping standing over a selectable default', function (): void {
    $host = groupedSongsHost(groupedSongsFixture(), fn (Table $table): Table => $table->defaultGroup('plays'));
    $host->tableGrouping = 'artist.name:desc';

    $host->mountTableGrouping();

    expect($host->tableGrouping)->toBe('artist.name:desc');
});

it('publishes a blank grouping as no grouping at all', function (): void {
    $host = groupedSongsHost(groupedSongsFixture());
    $host->tableGrouping = '';

    expect($host->dehydrateTableGrouping(null))->toBeNull();

    $host->tableGrouping = 'artist.name:desc';

    expect($host->dehydrateTableGrouping(null))->toBe('artist.name:desc');
});

it('maps every record on the page to its group boundaries', function (): void {
    $host = groupedSongsHost(groupedSongsFixture());
    $host->tableGrouping = 'artist.name';

    $boundaries = $host->getTableRecordGroupBoundaries();

    expect(array_keys($boundaries))->toBe([5, 3, 4, 1, 2])
        ->and($boundaries['5'])->toMatchArray([
            'key' => null,
            'title' => null,
            'description' => null,
            'isFirstOfGroup' => true,
            'isLastOfGroup' => true,
            'indexInGroup' => 0,
        ])
        ->and($boundaries['3'])->toMatchArray(['key' => 'Portishead', 'title' => 'Portishead', 'isFirstOfGroup' => true, 'isLastOfGroup' => false, 'indexInGroup' => 0])
        ->and($boundaries['4'])->toMatchArray(['key' => 'Portishead', 'isFirstOfGroup' => false, 'isLastOfGroup' => true, 'indexInGroup' => 1])
        ->and($boundaries['1'])->toMatchArray(['key' => 'Radiohead', 'isFirstOfGroup' => true, 'isLastOfGroup' => false, 'indexInGroup' => 0])
        ->and($boundaries['2'])->toMatchArray(['key' => 'Radiohead', 'isFirstOfGroup' => false, 'isLastOfGroup' => true, 'indexInGroup' => 1]);
});

/*
 * Collapse is keyed by title and so is the break, which is Filament's own test:
 * two keys that read the same are one group. Every other fixture here maps keys
 * to titles one to one, so this is the only case that tells the two apart.
 */
it('breaks on the title, so two keys reading the same are one group', function (): void {
    $host = groupedSongsHost(groupedSongsFixture(), fn (Table $table): Table => $table->groups([
        Group::make('artist.name')->getTitleFromRecordUsing(fn (): string => 'Everyone'),
    ]));
    $host->tableGrouping = 'artist.name';

    $boundaries = $host->getTableRecordGroupBoundaries();

    expect(array_column($boundaries, 'isFirstOfGroup'))->toBe([true, false, false, false, false])
        ->and(array_column($boundaries, 'isLastOfGroup'))->toBe([false, false, false, false, true])
        ->and(array_column($boundaries, 'indexInGroup'))->toBe([0, 1, 2, 3, 4])
        ->and(array_column($boundaries, 'key'))->toBe([null, 'Portishead', 'Portishead', 'Radiohead', 'Radiohead']);
});

it('describes each group header through the description closure', function (): void {
    $host = groupedSongsHost(groupedSongsFixture(), fn (Table $table): Table => $table->groups([
        Group::make('plays')->getDescriptionFromRecordUsing(fn (?string $title): string => 'Played ' . $title . ' times'),
    ]));
    $host->tableGrouping = 'plays';

    expect($host->getTableRecordGroupBoundaries()['1'])
        ->toMatchArray(['key' => '100', 'title' => '100', 'description' => 'Played 100 times']);
});

it('maps no boundaries at all when the table is not grouped', function (): void {
    $host = groupedSongsHost(groupedSongsFixture());

    expect($host->getTableRecordGroupBoundaries())->toBe([]);
});

it('resolves every record unpaginated under groupsOnly()', function (): void {
    $host = groupedSongsHost(groupedSongsFixture(), fn (Table $table): Table => $table->paginated([2])->groupsOnly());
    $host->tableGrouping = 'plays';

    expect($host->getTablePaginator())->toBeNull()
        ->and($host->getTableRecords())->toHaveCount(5);
});

it('applies the grouping order alone under groupsOnly(), skipping the sorts', function (): void {
    $host = groupedSongsHost(groupedSongsFixture(), fn (Table $table): Table => $table->groupsOnly()->defaultSort('title'));
    $host->tableGrouping = 'plays:desc';
    $host->sortTable('title');

    expect($host->getFilteredSortedTableQuery()?->getDQL())->toContain('ORDER BY song.plays desc')
        ->and($host->getFilteredSortedTableQuery()?->getDQL())->not->toContain('song.title')
        ->and($host->getFilteredSortedTableQuery()?->getDQL())->not->toContain('song.id');
});

it('eager loads no column under groupsOnly(), since no cell renders', function (): void {
    $recorder = new RecordingMiddleware();
    $entityManager = EntityManagerFactory::create($recorder);
    $radiohead = new Artist(1, 'Radiohead');

    EntityManagerFactory::seed($entityManager, $radiohead, new Song(1, 'Airbag', 100, $radiohead));

    // The grouping itself crosses nothing, so a join in the executed SQL can
    // only have come from the per-column eager load — and the read has to be of
    // the statement the page ran, since `getFilteredSortedTableQuery()` composes
    // a fresh builder that the eager-load loop never touched.
    $configure = fn (Table $table): Table => $table->columns([TextColumn::make('artist.name')]);

    $groupsOnly = groupedSongsHost($entityManager, fn (Table $table): Table => $configure($table)->groupsOnly());
    $groupsOnly->tableGrouping = 'plays';

    $recorder->reset();
    $groupsOnly->getTableRecords();
    $groupsOnlyStatements = $recorder->statements();

    $plain = groupedSongsHost($entityManager, $configure);
    $plain->tableGrouping = 'plays';

    $recorder->reset();
    $plain->getTableRecords();
    $plainStatements = $recorder->statements();

    expect(array_filter($groupsOnlyStatements, static fn (string $sql): bool => str_contains($sql, 'LEFT JOIN')))->toBe([])
        ->and(array_filter($plainStatements, static fn (string $sql): bool => str_contains($sql, 'LEFT JOIN')))->not->toBe([]);
});

it('fires no aggregate batch under groupsOnly(), where no cell renders', function (): void {
    $recorder = new RecordingMiddleware();
    $entityManager = EntityManagerFactory::create($recorder);

    EntityManagerFactory::seed($entityManager, new Song(1, 'Airbag', 100), new Song(2, 'Exit Music', 200));

    $configure = fn (Table $table): Table => $table->columns([
        TextColumn::make('title'),
        TextColumn::make('genres_count')->counts('genres'),
    ]);

    $groupsOnly = groupedSongsHost($entityManager, fn (Table $table): Table => $configure($table)->groupsOnly());
    $groupsOnly->tableGrouping = 'plays';

    $recorder->reset();
    $groupsOnly->getTableRecords();
    $groupsOnlyStatements = $recorder->statements();

    $plain = groupedSongsHost($entityManager, $configure);

    $recorder->reset();
    $plain->getTableRecords();
    $plainStatements = $recorder->statements();

    // Filament fires its aggregate selects here regardless; the port does not,
    // because under `groupsOnly()` the cells they fill never render.
    expect(array_filter($groupsOnlyStatements, static fn (string $sql): bool => str_contains($sql, 'GROUP BY')))->toBe([])
        ->and(array_filter($plainStatements, static fn (string $sql): bool => str_contains($sql, 'GROUP BY')))->not->toBe([]);
});

/**
 * The five-song set the boundary cases read, paged two at a time and grouped by
 * artist — so page 1 ends in the middle of a group and the trailing question has
 * something real to answer.
 *
 * @param (Closure(Table): Table)|null $configure
 */
function groupedBoundaryHost(EntityManager $entityManager, bool $summarized, ?Closure $configure = null): TableHost
{
    $host = groupedSongsHost($entityManager, function (Table $table) use ($summarized, $configure): Table {
        $table = $table
            ->columns([$summarized
                ? TextColumn::make('plays')->summarize(Sum::make())
                : TextColumn::make('plays')])
            ->paginated([2]);

        return $configure instanceof Closure ? $configure($table) : $table;
    });

    $host->tableGrouping = 'artist.name';

    return $host;
}

/*
 * Without a summary nothing hangs on the answer, so the page's last record simply
 * closes its group and no statement is spent finding out whether it really does.
 */
it('closes the last group of an unsummarized page without asking the database', function (): void {
    $recorder = new RecordingMiddleware();
    $host = groupedBoundaryHost(groupedSongsFixture($recorder), summarized: false);

    $host->getTableRecords();

    $recorder->reset();

    $boundaries = $host->getTableRecordGroupBoundaries();

    expect($recorder->statements())->toBe([])
        ->and(array_last($boundaries)['isLastOfGroup'])->toBeTrue();
});

/*
 * With one, the trailing summary row hangs on it — so the group is followed past
 * the page break, and the row is suppressed where it turns out to continue.
 */
it('follows the last group past the page break when the table has a summary', function (): void {
    $recorder = new RecordingMiddleware();
    $host = groupedBoundaryHost(groupedSongsFixture($recorder), summarized: true);

    $host->getTableRecords();

    $recorder->reset();

    $boundaries = $host->getTableRecordGroupBoundaries();

    expect($recorder->statements())->toHaveCount(1)
        ->and(array_last($boundaries)['isLastOfGroup'])->toBeFalse();
});

it('still closes the last group where the next page starts a new one', function (): void {
    // One record a page, so page one is the whole of the orphan's group and the
    // record after it belongs to another.
    $host = groupedBoundaryHost(groupedSongsFixture(), summarized: true, configure: fn (Table $table): Table => $table->paginated([1]));

    $boundaries = $host->getTableRecordGroupBoundaries();

    expect(array_last($boundaries)['isLastOfGroup'])->toBeTrue();
});

/*
 * The map has two readers by design — the group header rows and the trailing
 * summary row — and on a summarized table each read would otherwise pay the
 * probe's statement, once per record where a template reads it inside the loop.
 */
it('answers a second read of the boundary map from the memo', function (): void {
    $recorder = new RecordingMiddleware();
    $host = groupedBoundaryHost(groupedSongsFixture($recorder), summarized: true);

    $first = $host->getTableRecordGroupBoundaries();

    $recorder->reset();

    expect($host->getTableRecordGroupBoundaries())->toBe($first)
        ->and($recorder->statements())->toBe([]);
});

it('recomputes the boundary map once the records cache is flushed', function (): void {
    $recorder = new RecordingMiddleware();
    $host = groupedBoundaryHost(groupedSongsFixture($recorder), summarized: true);

    $byArtist = array_column($host->getTableRecordGroupBoundaries(), 'title');

    $host->groupTable('plays');

    $recorder->reset();

    $byPlays = array_column($host->getTableRecordGroupBoundaries(), 'title');

    expect($recorder->statements())->not->toBe([])
        ->and($byPlays)->not->toBe($byArtist);
});
