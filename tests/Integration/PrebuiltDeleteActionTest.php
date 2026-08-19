<?php

declare(strict_types=1);

namespace Sylament\Tables\Tests\Integration;

use Doctrine\ORM\EntityManagerInterface;
use Sylament\Tables\Tests\Fixtures\Doctrine\Entity\Song;
use Sylament\Tables\Tests\Fixtures\Doctrine\EntityManagerFactory;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;

uses(InteractsWithLiveComponents::class);

/*
 * The end-to-end pin for actions' prebuilt `DeleteAction`: it only deletes
 * anything when a table host resolves it, binds the row's record and calls it,
 * so — like `RecordActionsResolutionTest` — the test sits here rather than in
 * actions' own suite, and the src-mirror rule is deliberately relaxed for it.
 *
 * Kernel reboot is disabled for the whole flow: the fixture database is an
 * in-memory SQLite built by a container service, so a reboot between the two
 * live requests would hand the second one an empty, freshly-migrated database
 * and the delete would prove nothing.
 *
 * The fixture kernel registers the notifications bundle (and a session) so this
 * suite renders the whole M2 delete shape — confirm, delete, toast. That is
 * also what makes it the end-to-end pin for actions' guarded notification
 * integration: `sylament/actions` only *suggests* `sylament/notifications`, and
 * a `DeleteAction`'s `successNotificationTitle('Deleted')` becomes a real
 * `sylament:notification` browser event only when both bundles are booted.
 */

/**
 * @return array{KernelBrowser, EntityManagerInterface}
 */
function seededSongsClient(): array
{
    $client = test()->getContainer()->get('test.client');
    $client->disableReboot();

    $entityManager = test()->getContainer()->get(EntityManagerInterface::class);

    EntityManagerFactory::seed(
        $entityManager,
        new Song(1, 'Airbag', 100),
        new Song(2, 'Let Down', 200),
    );

    return [$client, $entityManager];
}

it('deletes the row from the database through Doctrine', function (): void {
    [$client, $entityManager] = seededSongsClient();

    $this->createLiveComponent('DeletableSongsTable', client: $client)
        ->call('mountAction', ['name' => 'delete', 'arguments' => [], 'context' => ['table' => true, 'recordKey' => '2']])
        ->call('callMountedAction');

    $entityManager->clear();

    expect($entityManager->find(Song::class, 2))->toBeNull()
        ->and($entityManager->find(Song::class, 1))->not->toBeNull();
});

it('re-renders the table without the deleted row', function (): void {
    [$client] = seededSongsClient();

    $component = $this->createLiveComponent('DeletableSongsTable', client: $client)
        ->call('mountAction', ['name' => 'delete', 'arguments' => [], 'context' => ['table' => true, 'recordKey' => '2']])
        ->call('callMountedAction');

    expect((string) $component->render())->toContain('Airbag')
        ->not->toContain('Let Down');
});

it('confirms before deleting, leaving the record until the mounted action is called', function (): void {
    [$client, $entityManager] = seededSongsClient();

    $component = $this->createLiveComponent('DeletableSongsTable', client: $client)
        ->call('mountAction', ['name' => 'delete', 'arguments' => [], 'context' => ['table' => true, 'recordKey' => '2']]);

    expect((string) $component->render())->toContain('Are you sure you would like to do this?')
        ->and($entityManager->find(Song::class, 2))->not->toBeNull();
});

it('dispatches the success toast as a browser event', function (): void {
    [$client] = seededSongsClient();

    $component = $this->createLiveComponent('DeletableSongsTable', client: $client)
        ->call('mountAction', ['name' => 'delete', 'arguments' => [], 'context' => ['table' => true, 'recordKey' => '2']])
        ->call('callMountedAction');

    expect((string) $component->render())->toContain('sylament:notification')
        ->toContain('sy-no-notification')
        ->toContain('sy-status-success')
        ->toContain('Deleted');
});
