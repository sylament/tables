<?php

declare(strict_types=1);

use Sylament\Tables\Table;
use Sylament\Tables\Tests\Fixtures\Concerns\TableHost;
use Sylament\Tables\Tests\Fixtures\Doctrine\Entity\Song;

it('answers no record classes until they are set', function (): void {
    expect(new TableHost()->getTable()->getRecordClasses(new Song(1, 'Airbag', 100)))->toBe([]);
});

it('wraps a single class string into a list', function (): void {
    $table = new TableHost(fn (Table $table): Table => $table->recordClasses('sy-late'))->getTable();

    expect($table->getRecordClasses(new Song(1, 'Airbag', 100)))->toBe(['sy-late']);
});

it('keeps a list and a conditional map as given', function (): void {
    $list = new TableHost(fn (Table $table): Table => $table->recordClasses(['one', 'two']))->getTable();
    $map = new TableHost(fn (Table $table): Table => $table->recordClasses(
        fn (Song $song): array => ['sy-long' => $song->getPlays() > 150, 'sy-short' => false],
    ))->getTable();

    expect($list->getRecordClasses(new Song(1, 'Airbag', 100)))->toBe(['one', 'two'])
        ->and($map->getRecordClasses(new Song(1, 'Airbag', 200)))->toBe(['sy-long' => true, 'sy-short' => false]);
});

it('turns a null closure result into an empty list', function (): void {
    $table = new TableHost(fn (Table $table): Table => $table->recordClasses(fn (): ?string => null))->getTable();

    expect($table->getRecordClasses(new Song(1, 'Airbag', 100)))->toBe([]);
});
