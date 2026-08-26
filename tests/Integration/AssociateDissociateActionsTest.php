<?php

declare(strict_types=1);

namespace Sylament\Tables\Tests\Integration;

use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use Sylament\Forms\Components\Select;
use Sylament\Schemas\Exceptions\ValidationException;
use Sylament\Tables\Tests\Fixtures\Doctrine\Entity\Album;
use Sylament\Tables\Tests\Fixtures\Doctrine\Entity\Artist;
use Sylament\Tables\Tests\Fixtures\Doctrine\Entity\Song;
use Sylament\Tables\Tests\Fixtures\Doctrine\RecordingMiddleware;
use Sylament\Tables\Tests\Fixtures\Twig\Components\ArtistSongsActionsTable;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;
use Symfony\UX\LiveComponent\Test\TestLiveComponent;

uses(InteractsWithLiveComponents::class);

/*
 * The end-to-end pin for actions' associate family: only a table host supplies
 * a relationship, and the actions test kernel registers neither tables nor
 * forms — so, exactly as with `PrebuiltDeleteActionTest`, the behavioural cases
 * sit here and the src-mirror rule is deliberately relaxed for this file.
 *
 * Kernel reboot is disabled throughout: the fixture database is an in-memory
 * SQLite held by a container service, so a reboot between the mount and the
 * confirmed call would hand the second request an empty one.
 *
 * The rig is seeded without `EntityManagerFactory::seed()` — that clears the
 * identity map, and these cases read the same manager the live request writes
 * through.
 */

/**
 * Radiohead (1) owning songs 10/11/12, Portishead (2) owning song 20, and song
 * 30 owned by nobody — the three arms of the one-to-many candidate query. The
 * unowned one is titled `Exit Music` deliberately: it sorts **before** song 20's
 * `Glory Box` while carrying the higher key, so the candidate list's title order
 * and its key order disagree and the select's `ORDER BY` is not vacuous.
 *
 * @return array{KernelBrowser, EntityManagerInterface, RecordingMiddleware}
 */
function seededArtistSongsClient(): array
{
    $client = test()->getContainer()->get('test.client');
    $client->disableReboot();

    $entityManager = test()->getContainer()->get(EntityManagerInterface::class);
    $recorder = test()->getContainer()->get(RecordingMiddleware::class);

    $radiohead = new Artist(1, 'Radiohead');
    $portishead = new Artist(2, 'Portishead');

    foreach ([
        $radiohead,
        $portishead,
        new Song(10, 'Airbag', 100, $radiohead),
        new Song(11, 'Karma Police', 110, $radiohead),
        new Song(12, 'No Surprises', 120, $radiohead),
        new Song(20, 'Glory Box', 200, $portishead),
        new Song(30, 'Exit Music', 300),
    ] as $entity) {
        $entityManager->persist($entity);
    }

    $entityManager->flush();
    $recorder->reset();

    return [$client, $entityManager, $recorder];
}

/**
 * @param array<string, mixed> $props
 */
function mountedAssociate(KernelBrowser $client, array $props = []): TestLiveComponent
{
    return test()->createLiveComponent(ArtistSongsActionsTable::class, [...['artistId' => 1], ...$props], client: $client)
        ->call('mountAction', ['name' => 'associate', 'arguments' => [], 'context' => ['table' => true]]);
}

function artistIdOfSong(EntityManagerInterface $entityManager, int $id): ?int
{
    $entityManager->clear();

    return $entityManager->find(Song::class, $id)?->getArtist()?->getId();
}

it("offers every song that is not this artist's and associates the picked one", function (): void {
    [$client, $entityManager] = seededArtistSongsClient();

    $component = mountedAssociate($client);
    $host = $component->component();
    $select = $host->getMountedActionSchema()?->getComponent('recordId');

    // The `IS NULL OR <> :owner` arm: another artist's song and the unattributed
    // one, never one of artist 1's own.
    expect($select?->getSearchResults(''))->toBe([30 => 'Exit Music', 20 => 'Glory Box'])
        ->and($select?->getSearchResults('exit'))->toBe([30 => 'Exit Music'])
        ->and($select?->getSearchResults('airbag'))->toBe([]);

    $component->set('mountedActionsData.0.recordId', 30)->call('callMountedAction');

    expect(artistIdOfSong($entityManager, 30))->toBe(1);
});

it('re-points a song that belongs to another artist', function (): void {
    [$client, $entityManager] = seededArtistSongsClient();

    mountedAssociate($client)
        ->set('mountedActionsData.0.recordId', 20)
        ->call('callMountedAction');

    expect(artistIdOfSong($entityManager, 20))->toBe(1);
});

it('toasts, unmounts and re-renders the table with the new row', function (): void {
    [$client] = seededArtistSongsClient();

    $component = mountedAssociate($client)
        ->set('mountedActionsData.0.recordId', 30)
        ->call('callMountedAction');

    $html = (string) $component->render();

    expect($component->component()->mountedActions)->toBe([])
        ->and($html)->toContain('Associated')
        ->and($html)->toContain('sy-status-success')
        ->and($html)->toContain('Exit Music');
});

it('keeps the modal open on a reset select when associating another', function (): void {
    [$client, $entityManager] = seededArtistSongsClient();

    $component = mountedAssociate($client)
        ->set('mountedActionsData.0.recordId', 30)
        ->call('callMountedAction', ['arguments' => ['another' => true]]);

    expect(artistIdOfSong($entityManager, 30))->toBe(1)
        ->and($component->component()->mountedActions)->toHaveCount(1)
        ->and($component->component()->getMountedActionData(0))->toBe(['recordId' => null])
        ->and((string) $component->render())->toContain('Associated');
});

it('associates two songs in one call when multiple() is on', function (): void {
    [$client, $entityManager] = seededArtistSongsClient();

    mountedAssociate($client, ['multiple' => true])
        ->set('mountedActionsData.0.recordId', [20, 30])
        ->call('callMountedAction');

    expect(artistIdOfSong($entityManager, 20))->toBe(1)
        ->and(artistIdOfSong($entityManager, 30))->toBe(1);
});

it('preloads the candidates as options when preloadRecordSelect() is on', function (): void {
    [$client] = seededArtistSongsClient();

    $select = mountedAssociate($client, ['preloads' => true])->component()->getMountedActionSchema()?->getComponent('recordId');

    expect($select?->getOptions())->toBe([30 => 'Exit Music', 20 => 'Glory Box']);
});

it("fails the select's own membership check on a key it never offered", function (): void {
    [$client, $entityManager] = seededArtistSongsClient();

    $component = mountedAssociate($client)->set('mountedActionsData.0.recordId', 11);

    expect(fn (): TestLiveComponent => $component->call('callMountedAction'))
        ->toThrow(ValidationException::class)
        ->and(artistIdOfSong($entityManager, 11))->toBe(1);
});

it('re-resolves the submitted key through the candidate query and refuses a smuggled one', function (): void {
    // The select is handed a static option map naming one of this artist's own
    // songs, so validation passes and the action's own re-resolution is what
    // refuses — the guard behind the select, which a reshaped recordSelect() is
    // otherwise free to open.
    [$client, $entityManager] = seededArtistSongsClient();

    $component = mountedAssociate($client, ['offersEverySong' => true])->set('mountedActionsData.0.recordId', 11);

    expect(fn (): TestLiveComponent => $component->call('callMountedAction'))
        ->toThrow(LogicException::class, 'was asked to associate a record its select never offered')
        ->and(artistIdOfSong($entityManager, 11))->toBe(1);
});

it('refuses an empty submission in the action body rather than reporting success', function (): void {
    // The select is `required()`, so the mounted path never reaches the body —
    // the guard is for a `using()` or direct-call consumer, and is what stops a
    // write that never happened from toasting "Associated".
    [$client, $entityManager] = seededArtistSongsClient();

    $host = test()->createLiveComponent(ArtistSongsActionsTable::class, ['artistId' => 1], client: $client)->component();
    $host->mountAction('associate', [], ['table' => true]);

    $schema = $host->getMountedActionSchema();
    $action = $host->getMountedAction() ?? throw new LogicException('Nothing mounted.');
    $action->data(['recordId' => null]);

    expect(fn (): mixed => $action->call(['schema' => $schema, 'form' => $schema]))
        ->toThrow(LogicException::class, 'submitted with no record to associate')
        ->and(artistIdOfSong($entityManager, 30))->toBeNull();
});

it('dissociates a row, titling the confirmation with the record', function (): void {
    [$client, $entityManager] = seededArtistSongsClient();

    $component = test()->createLiveComponent(ArtistSongsActionsTable::class, ['artistId' => 1], client: $client)
        ->call('mountAction', ['name' => 'dissociate', 'arguments' => [], 'context' => ['table' => true, 'recordKey' => '10']]);

    expect((string) $component->render())->toContain('Dissociate Airbag');

    $html = (string) $component->call('callMountedAction')->render();

    expect(artistIdOfSong($entityManager, 10))->toBeNull()
        ->and(artistIdOfSong($entityManager, 11))->toBe(1)
        ->and($html)->toContain('Dissociated')
        ->and($html)->toContain('sy-status-success');
});

it('dissociates the whole selection through the fetching arm in one flush', function (): void {
    [$client, $entityManager, $recorder] = seededArtistSongsClient();

    test()->createLiveComponent(ArtistSongsActionsTable::class, ['artistId' => 1], client: $client)
        ->set('selectedTableRecords', ['10', '11', '12'])
        ->call('mountAction', ['name' => 'dissociate', 'arguments' => [], 'context' => ['table' => true, 'bulk' => true]])
        ->call('callMountedAction');

    $updates = array_values(array_filter($recorder->statements(), static fn (string $sql): bool => str_starts_with($sql, 'UPDATE')));

    expect(artistIdOfSong($entityManager, 10))->toBeNull()
        ->and(artistIdOfSong($entityManager, 11))->toBeNull()
        ->and(artistIdOfSong($entityManager, 12))->toBeNull()
        ->and($updates)->toHaveCount(3);
});

it('dissociates the whole selection in one DQL update when the records are not fetched', function (): void {
    [$client, $entityManager, $recorder] = seededArtistSongsClient();

    $component = test()->createLiveComponent(ArtistSongsActionsTable::class, ['artistId' => 1, 'fetchesRecords' => false], client: $client)
        ->set('selectedTableRecords', ['10', '11', '12'])
        ->call('mountAction', ['name' => 'dissociate', 'arguments' => [], 'context' => ['table' => true, 'bulk' => true]]);

    $recorder->reset();

    $html = (string) $component->call('callMountedAction')->render();
    $updates = array_values(array_filter($recorder->statements(), static fn (string $sql): bool => str_starts_with($sql, 'UPDATE')));

    expect($updates)->toHaveCount(1)
        ->and($updates[0])->toContain('IN (?, ?, ?)')
        ->and(artistIdOfSong($entityManager, 10))->toBeNull()
        ->and(artistIdOfSong($entityManager, 11))->toBeNull()
        ->and(artistIdOfSong($entityManager, 12))->toBeNull()
        ->and($html)->toContain('Dissociated')
        ->and($html)->toContain('sy-status-success')
        ->and($html)->not->toContain('Failed to dissociate');
});

it('refuses to dissociate from an orphan-removal mapping, in both arms', function (): void {
    [$client, $entityManager, $recorder] = seededArtistSongsClient();

    $radiohead = $entityManager->find(Artist::class, 1) ?? throw new LogicException('The rig lost its artist.');
    $entityManager->persist(new Album(1, 'OK Computer', $radiohead));
    $entityManager->flush();

    $row = test()->createLiveComponent(ArtistSongsActionsTable::class, ['artistId' => 1, 'association' => 'albums'], client: $client)
        ->call('mountAction', ['name' => 'dissociate', 'arguments' => [], 'context' => ['table' => true, 'recordKey' => '1']]);

    expect(fn (): TestLiveComponent => $row->call('callMountedAction'))
        ->toThrow(LogicException::class, 'orphanRemoval');

    $bulk = test()->createLiveComponent(ArtistSongsActionsTable::class, ['artistId' => 1, 'association' => 'albums', 'fetchesRecords' => false], client: $client)
        ->set('selectedTableRecords', ['1'])
        ->call('mountAction', ['name' => 'dissociate', 'arguments' => [], 'context' => ['table' => true, 'bulk' => true]]);

    $recorder->reset();

    expect(fn (): TestLiveComponent => $bulk->call('callMountedAction'))
        ->toThrow(LogicException::class, 'orphanRemoval')
        ->and(array_filter($recorder->statements(), static fn (string $sql): bool => str_starts_with($sql, 'UPDATE')))->toBe([]);
});

it('refuses to dissociate an inverse-side many-to-many, in both arms', function (): void {
    [$client, , $recorder] = seededArtistSongsClient();

    $bulk = test()->createLiveComponent(ArtistSongsActionsTable::class, ['artistId' => 1, 'association' => 'collaborations', 'fetchesRecords' => false], client: $client)
        ->set('selectedTableRecords', ['10'])
        ->call('mountAction', ['name' => 'dissociate', 'arguments' => [], 'context' => ['table' => true, 'bulk' => true]]);

    $recorder->reset();

    expect(fn (): TestLiveComponent => $bulk->call('callMountedAction'))
        ->toThrow(LogicException::class, 'DetachBulkAction')
        ->and(array_filter($recorder->statements(), static fn (string $sql): bool => str_starts_with($sql, 'UPDATE')))->toBe([]);

    $fetching = test()->createLiveComponent(ArtistSongsActionsTable::class, ['artistId' => 1, 'association' => 'collaborations'], client: $client)
        ->set('selectedTableRecords', ['10'])
        ->call('mountAction', ['name' => 'dissociate', 'arguments' => [], 'context' => ['table' => true, 'bulk' => true]]);

    expect(fn (): TestLiveComponent => $fetching->call('callMountedAction'))
        ->toThrow(LogicException::class, 'DetachBulkAction');
});

/*
 * The select's scope, its search columns and its case folding. These mount
 * in-process rather than through `->call()`, the shape the empty-submission case
 * above uses: nothing here submits, and the closure's recorded arguments are
 * read off the very host that ran it.
 */

/**
 * @param array<string, mixed> $props
 */
function associateHost(KernelBrowser $client, array $props = []): ArtistSongsActionsTable
{
    $host = test()->createLiveComponent(ArtistSongsActionsTable::class, [...['artistId' => 1], ...$props], client: $client)->component();
    $host->mountAction('associate', [], ['table' => true]);

    return $host;
}

function associateRecordSelect(ArtistSongsActionsTable $host): Select
{
    $select = $host->getMountedActionSchema()?->getComponent('recordId');

    return $select instanceof Select ? $select : throw new LogicException('The associate modal carries no record select.');
}

it('narrows the candidates through recordSelectOptionsQuery(), handed the builder by name and the search beside it', function (): void {
    [$client] = seededArtistSongsClient();
    $host = associateHost($client, ['optionsQueryArm' => 'name']);
    $select = associateRecordSelect($host);

    expect($select->getSearchResults(''))->toBe([30 => 'Exit Music'])
        ->and($select->getSearchResults('exit'))->toBe([30 => 'Exit Music'])
        ->and($host->optionsQuerySearches)->toBe(['', 'exit']);
});

it('hands recordSelectOptionsQuery() the builder by type as well, and scopes the preloaded options with it', function (): void {
    [$client] = seededArtistSongsClient();

    expect(associateRecordSelect(associateHost($client, ['optionsQueryArm' => 'type', 'preloads' => true]))->getOptions())
        ->toBe([20 => 'Glory Box']);
});

it('hydrates and titles the candidates one by one once the table titles them itself', function (): void {
    [$client] = seededArtistSongsClient();
    $select = associateRecordSelect(associateHost($client, ['titlesByClosure' => true]));

    // The projection arm cannot answer these: it reads the title column, which
    // says `Exit Music`. Only the hydrating arm runs the table's recordTitle().
    expect($select->getSearchResults(''))->toBe([30 => 'EXIT MUSIC', 20 => 'GLORY BOX']);
});

it("titles the options through the action's own record-title closure, which no table title can stand in for", function (): void {
    [$client] = seededArtistSongsClient();
    $select = associateRecordSelect(associateHost($client, ['actionTitlesByClosure' => true]));

    // The projection arm reads the title column, which says `Exit Music`; only
    // the hydrating arm runs the action's own recordTitle().
    expect($select->getSearchResults(''))->toBe([30 => 'Song: Exit Music', 20 => 'Song: Glory Box']);
});

it("projects the action's own title attribute over a table that titles by closure", function (): void {
    [$client, $entityManager] = seededArtistSongsClient();
    $select = associateRecordSelect(associateHost($client, ['titlesByClosure' => true, 'actionTitleAttribute' => 'title']));

    $entityManager->clear();

    // The action's own attribute overrides the table's closure, so the answer
    // is the raw column rather than the closure's `EXIT MUSIC` — and, because
    // both arms would read the same column here, the projection is what the
    // empty identity map proves: nothing was hydrated to title anything.
    expect($select->getSearchResults(''))->toBe([30 => 'Exit Music', 20 => 'Glory Box'])
        ->and($entityManager->getUnitOfWork()->tryGetById(30, Song::class))->toBeFalse();
});

it('leaves an options query that orders itself alone', function (): void {
    [$client] = seededArtistSongsClient();
    $select = associateRecordSelect(associateHost($client, ['optionsQueryArm' => 'order']));

    // By title it is Exit Music then Glory Box; by plays it is the other way
    // round, so this answer is the caller's ORDER BY rather than the action's.
    expect($select->getSearchResults(''))->toBe([20 => 'Glory Box', 30 => 'Exit Music']);
});

it('searches the columns recordSelectSearchColumns() names rather than the title', function (): void {
    [$client] = seededArtistSongsClient();
    $select = associateRecordSelect(associateHost($client, ['searchColumns' => ['plays']]));

    expect($select->getSearchColumns())->toBe(['plays'])
        ->and($select->getSearchResults('300'))->toBe([30 => 'Exit Music'])
        ->and($select->getSearchResults('Exit'))->toBe([]);
});

it('refuses a search column the target class maps no field for', function (): void {
    [$client] = seededArtistSongsClient();
    $select = associateRecordSelect(associateHost($client, ['searchColumns' => ['nope']]));

    expect(fn (): mixed => $select->getSearchResults('exit'))
        ->toThrow(LogicException::class, sprintf('The action [associate] searches [nope] on [%s], which maps no such field.', Song::class));
});

it('folds the search to lower case only when forceSearchCaseInsensitive() says so', function (): void {
    [$client, , $recorder] = seededArtistSongsClient();

    $plain = associateRecordSelect(associateHost($client));
    $recorder->reset();
    $plain->getSearchResults('ExIt');
    $withoutFlag = $recorder->statements();

    $folded = associateRecordSelect(associateHost($client, ['forcesCaseInsensitiveSearch' => true]));
    $recorder->reset();
    $folded->getSearchResults('ExIt');
    $withFlag = $recorder->statements();

    expect(titleFoldingSelections($withoutFlag))->toBe([])
        ->and(titleFoldingSelections($withFlag))->toHaveCount(1);
});

/**
 * The statements whose LIKE folds the title column — SQLite renders the DQL
 * `LOWER()` as `LOWER(s0_.title) LIKE ?`, and nothing else in this rig emits one.
 *
 * @param list<string> $statements
 *
 * @return list<string>
 */
function titleFoldingSelections(array $statements): array
{
    return array_values(array_filter(
        $statements,
        static fn (string $sql): bool => (bool) preg_match('/LOWER\(\w+\.title_?\d*\) LIKE/i', $sql),
    ));
}

/*
 * A table whose `relationship()` closure answers null cannot render, so the bulk
 * action's hostless refusal is unreachable through a mounted request: the host
 * comes from the container instead, wired exactly as a request's would be, and
 * is driven directly. The selection stays empty because resolving a non-empty
 * one wants the table's query, which that same missing relationship denies —
 * the refusal under test runs before the batch is touched either way. The
 * non-bulk refusal needs no table at all and is pinned in the actions suite.
 */

it('refuses to dissociate a selection without a table declaring a relationship', function (): void {
    $host = $this::getContainer()->get(ArtistSongsActionsTable::class);
    $host->artistId = 999;
    $host->mountAction('dissociate', [], ['table' => true, 'bulk' => true]);

    expect(fn (): mixed => $host->callMountedAction())
        ->toThrow(LogicException::class, 'dissociates selected records through a relationship, which only a table declaring relationship() can supply');
});
