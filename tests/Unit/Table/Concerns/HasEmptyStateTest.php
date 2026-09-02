<?php

declare(strict_types=1);

use Sylament\Actions\Action;
use Sylament\Actions\ActionGroup;
use Sylament\Actions\BulkAction;
use Sylament\Tables\Table;
use Sylament\Tables\Tests\Fixtures\Concerns\TableHost;
use Sylament\Tables\Tests\Fixtures\Doctrine\Entity\Song;
use Sylament\Tables\Tests\Fixtures\Doctrine\EntityManagerFactory;
use Twig\Markup;

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

it('answers no custom empty state until one is set, then the evaluated markup', function (): void {
    $bare = new TableHost()->getTable();
    $custom = new TableHost(fn (Table $table): Table => $table->emptyState(new Markup('<p>Nothing here</p>', 'UTF-8')))->getTable();
    $byClosure = new TableHost(fn (Table $table): Table => $table
        ->emptyState(fn (Table $table): Markup => new Markup('<p>No ' . $table->getPluralRecordLabel() . '</p>', 'UTF-8')))->getTable();

    expect($bare->getEmptyState())->toBeNull()
        ->and((string) $custom->getEmptyState())->toBe('<p>Nothing here</p>')
        ->and((string) $byClosure->getEmptyState())->toBe('<p>No records</p>');
});

it('registers empty state actions into the flat maps, groups flattened', function (): void {
    $table = Table::make(new TableHost())
        ->emptyStateActions([
            Action::make('create'),
            ActionGroup::make([BulkAction::make('import')]),
        ]);

    expect($table->getEmptyStateActions())->toHaveCount(2)
        ->and(array_keys($table->getFlatActions()))->toBe(['create'])
        ->and(array_keys($table->getFlatBulkActions()))->toBe(['import'])
        ->and($table->getEmptyStateActions()[0]->getTable())->toBe($table);
});

it('flips the default description on for a create action registered as an empty state action', function (): void {
    $entityManager = EntityManagerFactory::create();
    $songs = $entityManager->createQueryBuilder()->select('song')->from(Song::class, 'song');
    $table = new TableHost(fn (Table $table): Table => $table
        ->query($songs)
        ->emptyStateActions([Action::make('create')]))->getTable();

    expect($table->getEmptyStateDescription())->toBe('Create a song to get started.');
});

it('clears the list before pushing and appends with pushEmptyStateActions', function (): void {
    $table = Table::make(new TableHost())
        ->emptyStateActions([Action::make('create'), Action::make('import')]);

    $table->emptyStateActions([Action::make('export')]);
    $table->pushEmptyStateActions([Action::make('sync')]);

    expect($table->getEmptyStateActions())->toHaveCount(2)
        ->and(array_map(static fn (Action $action): string => $action->getName(), $table->getEmptyStateActions()))->toBe(['export', 'sync']);
});

it('keeps the cached action unless the overwrite flag is set', function (): void {
    $kept = Table::make(new TableHost())
        ->emptyStateActions([$first = Action::make('create')])
        ->pushEmptyStateActions([Action::make('create')]);
    $overwritten = Table::make(new TableHost())
        ->emptyStateActions([Action::make('create')])
        ->pushEmptyStateActions([$second = Action::make('create')], shouldOverwriteExistingActions: true);

    expect($kept->getAction('create'))->toBe($first)
        ->and($overwritten->getAction('create'))->toBe($second);
});

it('refuses an empty state action that is neither an action nor a group', function (): void {
    Table::make(new TableHost())->emptyStateActions(['create']);
})->throws(InvalidArgumentException::class, 'Table empty state actions must be an instance of');
