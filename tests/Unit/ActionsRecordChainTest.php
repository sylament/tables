<?php

declare(strict_types=1);

namespace Sylament\Tables\Tests\Unit;

use Sylament\Actions\Action;
use Sylament\Actions\DeleteAction;
use Sylament\Actions\DeleteBulkAction;
use Sylament\Tables\Table;
use Sylament\Tables\Tests\Fixtures\Concerns\TableHost;
use Sylament\Tables\Tests\Fixtures\Doctrine\Entity\Song;
use Sylament\Tables\Tests\Fixtures\Doctrine\EntityManagerFactory;

/*
 * Actions' record chains hop to a real `Table`, which only this suite can build —
 * the actions test kernel registers no tables bundle. The src-mirror is relaxed
 * for the same reason `ActionsBelongsToTableTest` sits here.
 */

it('hops to the table for the label, the plural, the title and the title attribute', function (): void {
    $entityManager = EntityManagerFactory::create();
    $songs = $entityManager->createQueryBuilder()->select('song')->from(Song::class, 'song');
    $table = new TableHost(fn (Table $table): Table => $table->query($songs)->recordTitleAttribute('title'))->getTable();
    $song = new Song(1, 'Airbag', 100);

    $delete = DeleteAction::make()->table($table)->record($song);
    $bulk = DeleteBulkAction::make()->table($table);
    $labelled = DeleteAction::make()->table(new TableHost(fn (Table $t): Table => $t->query($songs)->recordLabel('tune'))->getTable())->record($song);

    expect($delete->getRecordLabel())->toBe('song')
        ->and($delete->getPluralRecordLabel())->toBe('songs')
        ->and($delete->getRecordTitleAttribute())->toBe('title')
        ->and($delete->getRecordTitle())->toBe('Airbag')
        ->and($delete->getModalHeading())->toBe('Delete Airbag')
        ->and($bulk->getModalHeading())->toBe('Delete selected Songs')
        ->and($labelled->getRecordLabel())->toBe('tune')
        ->and($labelled->getPluralRecordLabel())->toBe('tunes')
        ->and(DeleteAction::make()->table(new TableHost(fn (Table $t): Table => $t->query($songs))->getTable())->record($song)->getRecordTitle())->toBe('song');
});

it('injects the table by name and by type into an evaluated closure', function (): void {
    $table = new TableHost()->getTable();
    $action = Action::make('x')->table($table);

    expect($action->evaluate(fn (Table $table): Table => $table))->toBe($table)
        ->and($action->evaluate(fn ($table): mixed => $table))->toBe($table);
});
