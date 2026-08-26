<?php

declare(strict_types=1);

use Sylament\Actions\Action;
use Sylament\Tables\Table;
use Sylament\Tables\Tests\Fixtures\Concerns\TableHost;
use Sylament\Tables\Tests\Fixtures\Doctrine\Entity\Song;
use Sylament\Tables\Tests\Fixtures\Doctrine\EntityManagerFactory;

it('names the empty state after the records and the create action', function (): void {
    $entityManager = EntityManagerFactory::create();
    $songs = $entityManager->createQueryBuilder()->select('song')->from(Song::class, 'song');
    $derived = new TableHost(fn (Table $table): Table => $table->query($songs))->getTable();
    $creatable = new TableHost(fn (Table $table): Table => $table->query($songs)->headerActions([Action::make('create')]))->getTable();
    $custom = new TableHost(fn (Table $table): Table => $table->query($songs)->emptyStateHeading('Nothing')->emptyStateDescription('Nothing at all'))->getTable();
    $cleared = new TableHost(fn (Table $table): Table => $table->query($songs)->headerActions([Action::make('create')])->emptyStateDescription(null))->getTable();
    $bare = new TableHost()->getTable();

    expect($derived->getEmptyStateHeading())->toBe('No songs')
        ->and($derived->getEmptyStateDescription())->toBeNull()
        ->and($creatable->getEmptyStateDescription())->toBe('Create a song to get started.')
        ->and($custom->getEmptyStateHeading())->toBe('Nothing')
        ->and($custom->getEmptyStateDescription())->toBe('Nothing at all')
        ->and($cleared->getEmptyStateDescription())->toBeNull()
        ->and($bare->getEmptyStateHeading())->toBe('No records');
});
