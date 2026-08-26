<?php

declare(strict_types=1);

namespace Sylament\Tables\Tests\Integration;

use Doctrine\ORM\EntityManagerInterface;
use RuntimeException;
use Sylament\Tables\Tests\Fixtures\Doctrine\Entity\Artist;
use Sylament\Tables\Tests\Fixtures\Doctrine\Entity\Song;
use Sylament\Tables\Tests\Fixtures\Doctrine\EntityManagerFactory;
use Sylament\Tables\Tests\Fixtures\Doctrine\RecordingConnection;
use Sylament\Tables\Tests\Fixtures\Doctrine\RecordingMiddleware;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;

uses(InteractsWithLiveComponents::class);

/*
 * The two `report-*` actions open no modal, so `mountAction` calls them in the
 * same request and the toast is in *that* response — a second
 * `callMountedAction` would find nothing mounted and render a page the flash
 * bag has already been drained for.
 *
 * The end-to-end pin for actions' `DeleteBulkAction`, sitting here for the
 * reason `PrebuiltDeleteActionTest` does: only a table host supplies a
 * selection, and the actions test kernel has neither a table nor a database.
 * Kernel reboot is disabled throughout — the fixture database is an in-memory
 * SQLite held by a container service, so a reboot between the mount and the
 * confirmed call would hand the second request an empty one.
 *
 * The transaction assertions read the `Recording*` middleware's statement list,
 * where {@see RecordingConnection} records BEGIN / COMMIT / ROLLBACK markers
 * beside the SQL. Each of those cases is written twice — with the flag and
 * without — because the ORM opens a transaction around every flush of its own,
 * so only the *contrast* proves the action's wrap did anything. That is also why
 * the committing case asserts the BEGIN lands **before the selection SELECT**
 * rather than merely around the DELETE: the flush's own transaction already
 * brackets the DELETE, so bracketing proves nothing, while only the action's
 * wrap can open a transaction before the records are even resolved.
 */

/**
 * @return array{KernelBrowser, EntityManagerInterface, RecordingMiddleware}
 */
function seededBulkSongsClient(): array
{
    $client = test()->getContainer()->get('test.client');
    $client->disableReboot();

    $entityManager = test()->getContainer()->get(EntityManagerInterface::class);
    $recorder = test()->getContainer()->get(RecordingMiddleware::class);

    EntityManagerFactory::seed(
        $entityManager,
        new Song(1, 'Airbag', 100),
        new Song(2, 'Paranoid Android', 200),
        new Song(3, 'Exit Music', 200),
        new Song(4, 'Let Down', 100),
    );

    $recorder->reset();

    return [$client, $entityManager, $recorder];
}

/**
 * The by-key delete's failing rig: `Song.artist_id` references `Artist` with no
 * cascade, so one seeded song is enough to make the DQL `DELETE` of its artist
 * violate a foreign key — once SQLite is asked to enforce them, which it does
 * not by default.
 *
 * @return array{KernelBrowser, EntityManagerInterface}
 */
function seededBulkArtistsClient(): array
{
    $client = test()->getContainer()->get('test.client');
    $client->disableReboot();

    $entityManager = test()->getContainer()->get(EntityManagerInterface::class);
    $entityManager->getConnection()->executeStatement('PRAGMA foreign_keys = ON');

    $radiohead = new Artist(1, 'Radiohead');

    EntityManagerFactory::seed($entityManager, $radiohead, new Song(1, 'Airbag', 100, $radiohead));

    return [$client, $entityManager];
}

/**
 * @return list<int>
 */
function survivingSongIds(EntityManagerInterface $entityManager): array
{
    $entityManager->clear();

    /** @var list<Song> $songs */
    $songs = $entityManager->getRepository(Song::class)->findBy([], ['id' => 'ASC']);

    return array_map(static fn (Song $song): int => $song->getId(), $songs);
}

/**
 * The confirmed bulk delete's *second* request alone: the recorder is reset
 * between the mount and the call, so the statement list starts where the action
 * body does and a position assertion means something.
 *
 * @return list<string>
 */
function confirmedBulkDeleteStatements(KernelBrowser $client, RecordingMiddleware $recorder, bool $usesTransaction): array
{
    $component = test()->createLiveComponent('BulkDeleteSongsTable', ['usesTransaction' => $usesTransaction], client: $client)
        ->set('selectedTableRecords', ['2'])
        ->call('mountAction', ['name' => 'delete', 'arguments' => [], 'context' => ['table' => true, 'bulk' => true]]);

    $recorder->reset();

    $component->call('callMountedAction');

    return $recorder->statements();
}

/**
 * The index of the first recorded statement starting with $prefix, or a
 * sentinel past every index so a "precedes" assertion fails on an absent one.
 *
 * @param list<string> $statements
 */
function firstStatementIndex(array $statements, string $prefix): int
{
    foreach ($statements as $index => $sql) {
        if (str_starts_with($sql, $prefix)) {
            return $index;
        }
    }

    return PHP_INT_MAX;
}

it('deletes exactly the selected records', function (): void {
    [$client, $entityManager] = seededBulkSongsClient();

    $this->createLiveComponent('BulkDeleteSongsTable', client: $client)
        ->set('selectedTableRecords', ['2', '4'])
        ->call('mountAction', ['name' => 'delete', 'arguments' => [], 'context' => ['table' => true, 'bulk' => true]])
        ->call('callMountedAction');

    expect(survivingSongIds($entityManager))->toBe([1, 3]);
});

it('deletes everything but the deselected records while tracking', function (): void {
    [$client, $entityManager] = seededBulkSongsClient();

    $this->createLiveComponent('BulkDeleteSongsTable', client: $client)
        ->set('isTrackingDeselectedTableRecords', true)
        ->set('deselectedTableRecords', ['1', '3'])
        ->call('mountAction', ['name' => 'delete', 'arguments' => [], 'context' => ['table' => true, 'bulk' => true]])
        ->call('callMountedAction');

    expect(survivingSongIds($entityManager))->toBe([1, 3]);
});

it('leaves a selected record the active search filters out', function (): void {
    [$client, $entityManager] = seededBulkSongsClient();

    $this->createLiveComponent('BulkDeleteSongsTable', client: $client)
        ->set('tableSearch', 'Let Down')
        ->set('selectedTableRecords', ['2', '4'])
        ->call('mountAction', ['name' => 'delete', 'arguments' => [], 'context' => ['table' => true, 'bulk' => true]])
        ->call('callMountedAction');

    expect(survivingSongIds($entityManager))->toBe([1, 2, 3]);
});

it('leaves a selected record the selectability callback refuses', function (): void {
    [$client, $entityManager] = seededBulkSongsClient();

    $this->createLiveComponent('BulkDeleteSongsTable', ['checksSelectable' => true], client: $client)
        ->set('selectedTableRecords', ['2', '3'])
        ->call('mountAction', ['name' => 'delete', 'arguments' => [], 'context' => ['table' => true, 'bulk' => true]])
        ->call('callMountedAction');

    expect(survivingSongIds($entityManager))->toBe([1, 3, 4]);
});

it('clears the selection props after the delete', function (): void {
    [$client] = seededBulkSongsClient();

    $host = $this->createLiveComponent('BulkDeleteSongsTable', client: $client)
        ->set('selectedTableRecords', ['2', '4'])
        ->call('mountAction', ['name' => 'delete', 'arguments' => [], 'context' => ['table' => true, 'bulk' => true]])
        ->call('callMountedAction')
        ->component();

    expect($host->selectedTableRecordKeys)->toBe([])
        ->and($host->deselectedTableRecords)->toBe([])
        ->and($host->isTrackingDeselectedTableRecords)->toBeFalse();
});

it('deletes by key in one statement without fetching the records', function (): void {
    [$client, $entityManager, $recorder] = seededBulkSongsClient();

    $this->createLiveComponent('BulkDeleteSongsTable', ['fetchesRecords' => false], client: $client)
        ->set('selectedTableRecords', ['2', '4'])
        ->call('mountAction', ['name' => 'delete', 'arguments' => [], 'context' => ['table' => true, 'bulk' => true]])
        ->call('callMountedAction');

    $deletes = array_values(array_filter(
        $recorder->statements(),
        static fn (string $sql): bool => str_starts_with($sql, 'DELETE'),
    ));

    expect(survivingSongIds($entityManager))->toBe([1, 3])
        ->and($deletes)->toHaveCount(1);
});

it('reports the affected row count of the by-key delete as the successful count', function (): void {
    [$client] = seededBulkSongsClient();

    $html = (string) $this->createLiveComponent('BulkDeleteSongsTable', ['fetchesRecords' => false], client: $client)
        ->set('selectedTableRecords', ['2', '4'])
        ->call('mountAction', ['name' => 'delete', 'arguments' => [], 'context' => ['table' => true, 'bulk' => true]])
        ->call('callMountedAction')
        ->render();

    // The status is the count comparison: `getSelectedRecords()` minted the total
    // from the two plucked keys, so only a matching affected-row count leaves the
    // action successful rather than reporting a partial delete.
    expect($html)->toContain('Deleted')
        ->and($html)->toContain('sy-status-success')
        ->and($html)->not->toContain('Failed to delete');
});

it('answers a failed by-key delete with the danger toast, leaving the rows', function (): void {
    [$client, $entityManager] = seededBulkArtistsClient();

    $html = (string) $this->createLiveComponent('BulkDeleteArtistsTable', client: $client)
        ->set('selectedTableRecords', ['1'])
        ->call('mountAction', ['name' => 'delete', 'arguments' => [], 'context' => ['table' => true, 'bulk' => true]])
        ->call('callMountedAction')
        ->render();

    $entityManager->clear();

    expect($html)->toContain('Failed to delete 1 records')
        ->and($html)->toContain('sy-status-danger')
        ->and($entityManager->getRepository(Artist::class)->findAll())->toHaveCount(1);
});

it('mints the counters off the selection and warns on a partial failure', function (): void {
    [$client] = seededBulkSongsClient();

    $html = (string) $this->createLiveComponent('BulkDeleteSongsTable', client: $client)
        ->set('selectedTableRecords', ['2', '3', '4'])
        ->call('mountAction', ['name' => 'report-one', 'arguments' => [], 'context' => ['table' => true, 'bulk' => true]])
        ->render();

    expect($html)->toContain('Archived 2 of 3')
        ->and($html)->toContain('sy-status-warning');
});

it('resolves the selection query by parameter name and by QueryBuilder type alike', function (): void {
    [$client] = seededBulkSongsClient();

    $html = (string) $this->createLiveComponent('BulkDeleteSongsTable', client: $client)
        ->set('selectedTableRecords', ['2', '4'])
        ->call('mountAction', ['name' => 'inspect-query', 'arguments' => [], 'context' => ['table' => true, 'bulk' => true]])
        ->render();

    // The fixture answers -1 when the two injections disagree, so a matching
    // count is what says both arms resolved the same constrained query.
    expect($html)->toContain('Both queries answered 2');
});

it('reports every record failed with a danger toast on a complete failure', function (): void {
    [$client] = seededBulkSongsClient();

    $html = (string) $this->createLiveComponent('BulkDeleteSongsTable', client: $client)
        ->set('selectedTableRecords', ['2', '3', '4'])
        ->call('mountAction', ['name' => 'report-all', 'arguments' => [], 'context' => ['table' => true, 'bulk' => true]])
        ->render();

    expect($html)->toContain('Failed to archive 3 records')
        ->and($html)->toContain('sy-status-danger');
});

it('wraps the delete in a transaction it commits', function (): void {
    [$client, $entityManager, $recorder] = seededBulkSongsClient();

    $statements = confirmedBulkDeleteStatements($client, $recorder, usesTransaction: true);
    $delete = firstStatementIndex($statements, 'DELETE');

    expect(survivingSongIds($entityManager))->toBe([1, 3, 4])
        ->and($statements)->not->toContain(RecordingConnection::ROLLBACK)
        ->and(firstStatementIndex($statements, RecordingConnection::BEGIN))
        ->toBeLessThan(firstStatementIndex($statements, 'SELECT'))
        ->and(array_key_last(array_filter($statements, static fn (string $sql): bool => $sql === RecordingConnection::COMMIT)))->toBeGreaterThan($delete);
});

it('opens no transaction of its own when the same delete runs unwrapped', function (): void {
    [$client, $entityManager, $recorder] = seededBulkSongsClient();

    $statements = confirmedBulkDeleteStatements($client, $recorder, usesTransaction: false);

    expect(survivingSongIds($entityManager))->toBe([1, 3, 4])
        ->and(firstStatementIndex($statements, 'SELECT'))
        ->toBeLessThan(firstStatementIndex($statements, RecordingConnection::BEGIN));
});

it('rolls the whole batch back when the bulk body throws', function (): void {
    [$client, $entityManager, $recorder] = seededBulkSongsClient();

    $component = $this->createLiveComponent('BulkDeleteSongsTable', ['usesTransaction' => true], client: $client)
        ->set('selectedTableRecords', ['2']);

    expect(fn (): object => $component->call('mountAction', ['name' => 'boom', 'arguments' => [], 'context' => ['table' => true, 'bulk' => true]]))
        ->toThrow(RuntimeException::class, 'The bulk body blew up.')
        ->and($recorder->statements())->toContain(RecordingConnection::ROLLBACK)
        ->and(survivingSongIds($entityManager))->toBe([1, 2, 3, 4]);
});

it('keeps the removal when the same throwing body runs outside a transaction', function (): void {
    [$client, $entityManager, $recorder] = seededBulkSongsClient();

    $component = $this->createLiveComponent('BulkDeleteSongsTable', client: $client)
        ->set('selectedTableRecords', ['2']);

    expect(fn (): object => $component->call('mountAction', ['name' => 'boom', 'arguments' => [], 'context' => ['table' => true, 'bulk' => true]]))
        ->toThrow(RuntimeException::class, 'The bulk body blew up.')
        ->and($recorder->statements())->not->toContain(RecordingConnection::ROLLBACK)
        ->and(survivingSongIds($entityManager))->toBe([1, 3, 4]);
});

it('rolls back when the bulk body cancels with the rollback flag', function (): void {
    [$client, $entityManager, $recorder] = seededBulkSongsClient();

    $this->createLiveComponent('BulkDeleteSongsTable', ['usesTransaction' => true], client: $client)
        ->set('selectedTableRecords', ['2'])
        ->call('mountAction', ['name' => 'give-up', 'arguments' => [], 'context' => ['table' => true, 'bulk' => true]]);

    expect($recorder->statements())->toContain(RecordingConnection::ROLLBACK)
        ->and(survivingSongIds($entityManager))->toBe([1, 2, 3, 4]);
});

it('keeps the removal when the same cancelling body runs outside a transaction', function (): void {
    [$client, $entityManager, $recorder] = seededBulkSongsClient();

    $this->createLiveComponent('BulkDeleteSongsTable', client: $client)
        ->set('selectedTableRecords', ['2'])
        ->call('mountAction', ['name' => 'give-up', 'arguments' => [], 'context' => ['table' => true, 'bulk' => true]]);

    expect($recorder->statements())->not->toContain(RecordingConnection::ROLLBACK)
        ->and(survivingSongIds($entityManager))->toBe([1, 3, 4]);
});

it('leaves the unselectable record behind on the by-key delete too', function (): void {
    [$client, $entityManager] = seededBulkSongsClient();

    $this->createLiveComponent('BulkDeleteSongsTable', ['checksSelectable' => true, 'fetchesRecords' => false], client: $client)
        ->set('selectedTableRecords', ['2', '3'])
        ->call('mountAction', ['name' => 'delete', 'arguments' => [], 'context' => ['table' => true, 'bulk' => true]])
        ->call('callMountedAction');

    expect(survivingSongIds($entityManager))->toBe([1, 3, 4]);
});
