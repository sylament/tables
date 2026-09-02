<?php

declare(strict_types=1);

use Doctrine\ORM\EntityManager;
use Doctrine\ORM\QueryBuilder;
use Sylament\Tables\Columns\TextColumn;
use Sylament\Tables\Table;
use Sylament\Tables\Tests\Fixtures\Concerns\TableHost;
use Sylament\Tables\Tests\Fixtures\Doctrine\Entity\Song;
use Sylament\Tables\Tests\Fixtures\Doctrine\EntityManagerFactory;

/**
 * Three positioned songs on the query lane, reorderable by `position` unless the
 * caller says otherwise.
 *
 * @return array{TableHost, EntityManager}
 */
function reorderRig(?Closure $configure = null): array
{
    $entityManager = EntityManagerFactory::create();

    EntityManagerFactory::seed(
        $entityManager,
        new Song(1, 'Airbag', 100, position: 1),
        new Song(2, 'Bones', 200, position: 2),
        new Song(3, 'Creep', 300, position: 3),
    );

    $host = new TableHost(fn (Table $table): Table => ($configure ?? static fn (Table $table): Table => $table)(
        $table
            ->query($entityManager->createQueryBuilder()->select('song')->from(Song::class, 'song'))
            ->reorderable('position'),
    ));

    return [$host, $entityManager];
}

/**
 * The stored position of every song, keyed by identifier, read past the identity
 * map so a write that never reached the database shows up as the old value.
 *
 * @return array<int, int|null>
 */
function storedPositions(EntityManager $entityManager): array
{
    $entityManager->clear();

    $positions = [];

    foreach ($entityManager->getRepository(Song::class)->findBy([], ['id' => 'ASC']) as $song) {
        $positions[$song->getId()] = $song->getPosition();
    }

    return $positions;
}

it('numbers the submitted order 1..N', function (): void {
    [$host, $entityManager] = reorderRig();

    $host->reorderTable(['3', '1', '2']);

    expect(storedPositions($entityManager))->toBe([1 => 2, 2 => 3, 3 => 1]);
});

it('reverses before numbering when the direction is descending', function (): void {
    [$host, $entityManager] = reorderRig(fn (Table $table): Table => $table->reorderable('position', direction: 'desc'));

    $host->reorderTable(['3', '1', '2']);

    expect(storedPositions($entityManager))->toBe([1 => 2, 2 => 1, 3 => 3]);
});

it('leaves a gap where a key the query does not answer for sat, numbering the rest', function (): void {
    [$host, $entityManager] = reorderRig(fn (Table $table): Table => $table
        ->modifyQueryUsing(fn (QueryBuilder $query): QueryBuilder => $query->andWhere('song.id != 2')));

    $host->reorderTable(['3', '2', '1']);

    expect(storedPositions($entityManager))->toBe([1 => 3, 2 => 2, 3 => 1]);
});

it('ignores non-scalar keys the client smuggled in', function (): void {
    [$host, $entityManager] = reorderRig();

    $host->reorderTable(['3', ['1'], '1']);

    expect(storedPositions($entityManager))->toBe([1 => 2, 2 => 2, 3 => 1]);
});

it('writes nothing when the table is not reorderable', function (): void {
    [$host, $entityManager] = reorderRig(fn (Table $table): Table => $table->reorderable('position', false));

    $host->reorderTable(['3', '2', '1']);

    expect(storedPositions($entityManager))->toBe([1 => 1, 2 => 2, 3 => 3]);
});

it('refuses to reorder a records() table, naming the lanes that can', function (): void {
    $host = new TableHost(fn (Table $table): Table => $table
        ->records(fn (): array => [['__key' => '1']])
        ->reorderable('position'));

    expect(fn (): mixed => $host->reorderTable(['1']))
        ->toThrow(LogicException::class, 'reorders records it does not query');
});

it('brackets the write with the table hooks', function (): void {
    $seen = [];

    [$host, $entityManager] = reorderRig(function (Table $table) use (&$seen): Table {
        return $table
            ->beforeReordering(function (array $order) use (&$seen): void {
                $seen['before'] = $order;
            })
            ->afterReordering(function (array $order) use (&$seen): void {
                $seen['after'] = $order;
            });
    });

    $host->reorderTable(['2', '1', '3']);

    expect($seen)->toBe(['before' => ['2', '1', '3'], 'after' => ['2', '1', '3']])
        ->and(storedPositions($entityManager))->toBe([1 => 2, 2 => 1, 3 => 3]);
});

it('orders by the reorder column alone while reordering, bypassing both sorts', function (): void {
    [$host] = reorderRig(fn (Table $table): Table => $table
        ->columns([TextColumn::make('title')->sortable()])
        ->defaultSort('plays', 'desc')
        ->reorderable('position', direction: 'desc'));

    $host->tableSort = 'title:asc';

    expect($host->getFilteredSortedTableQuery()?->getDQL())->toContain('ORDER BY song.title asc');

    $host->toggleTableReordering();

    expect($host->getFilteredSortedTableQuery()?->getDQL())->toBe(
        'SELECT song FROM ' . Song::class . ' song ORDER BY song.position desc',
    );
});

it('fetches every row while reordering unless the table paginates through it', function (): void {
    [$unpaginated] = reorderRig(fn (Table $table): Table => $table->paginated([2]));
    [$paginated] = reorderRig(fn (Table $table): Table => $table->paginated([2])->paginatedWhileReordering());

    expect($unpaginated->getTableRecords())->toHaveCount(2)
        ->and($unpaginated->getTablePaginator())->not->toBeNull();

    $unpaginated->toggleTableReordering();
    $paginated->toggleTableReordering();

    expect($unpaginated->getTableRecords())->toHaveCount(3)
        ->and($unpaginated->getTablePaginator())->toBeNull()
        ->and($paginated->getTableRecords())->toHaveCount(2)
        ->and($paginated->getTable()->isPaginatedWhileReordering())->toBeTrue();
});

it('toggles the reordering flag, gated by the definition', function (): void {
    [$reorderable] = reorderRig();
    [$unreorderable] = reorderRig(fn (Table $table): Table => $table->reorderable());

    $reorderable->toggleTableReordering();
    $unreorderable->toggleTableReordering();

    expect($reorderable->isTableReordering)->toBeTrue()
        ->and($reorderable->isTableReordering())->toBeTrue()
        ->and($unreorderable->isTableReordering)->toBeTrue()
        ->and($unreorderable->isTableReordering())->toBeFalse();

    $reorderable->toggleTableReordering();

    expect($reorderable->isTableReordering())->toBeFalse();
});
