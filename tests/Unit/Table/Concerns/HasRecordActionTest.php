<?php

declare(strict_types=1);

use Sylament\Actions\Action;
use Sylament\Actions\DeleteAction;
use Sylament\Tables\Table;
use Sylament\Tables\Tests\Fixtures\Concerns\TableHost;
use Sylament\Tables\Tests\Fixtures\Doctrine\Entity\Song;

it('answers no record action until one is set', function (): void {
    expect(new TableHost()->getTable()->getRecordAction(new Song(1, 'Airbag', 100)))->toBeNull();
});

it('answers a plain name unchanged', function (): void {
    $table = new TableHost(fn (Table $table): Table => $table->recordAction('edit'))->getTable();

    expect($table->getRecordAction(new Song(1, 'Airbag', 100)))->toBe('edit');
});

it('computes the name from a closure taking the record', function (): void {
    $table = new TableHost(fn (Table $table): Table => $table->recordAction(
        fn (Song $song): ?string => $song->getId() === 1 ? 'edit' : null,
    ))->getTable();

    expect($table->getRecordAction(new Song(1, 'Airbag', 100)))->toBe('edit')
        ->and($table->getRecordAction(new Song(2, 'Bones', 100)))->toBeNull();
});

it('collapses an action class-string onto its default name', function (): void {
    $prebuilt = new TableHost(fn (Table $table): Table => $table->recordAction(DeleteAction::class))->getTable();
    $nameless = new TableHost(fn (Table $table): Table => $table->recordAction(Action::class))->getTable();
    $notAnAction = new TableHost(fn (Table $table): Table => $table->recordAction(Song::class))->getTable();

    expect($prebuilt->getRecordAction(new Song(1, 'Airbag', 100)))->toBe('delete')
        ->and($nameless->getRecordAction(new Song(1, 'Airbag', 100)))->toBe(Action::class)
        ->and($notAnAction->getRecordAction(new Song(1, 'Airbag', 100)))->toBe(Song::class);
});

it('answers null for an empty name', function (): void {
    $table = new TableHost(fn (Table $table): Table => $table->recordAction(''))->getTable();

    expect($table->getRecordAction(new Song(1, 'Airbag', 100)))->toBeNull();
});
