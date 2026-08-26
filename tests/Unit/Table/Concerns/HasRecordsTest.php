<?php

declare(strict_types=1);

use Sylament\Tables\Table;
use Sylament\Tables\Tests\Fixtures\Concerns\TableHost;
use Sylament\Tables\Tests\Fixtures\Doctrine\Entity\Song;
use Sylament\Tables\Tests\Fixtures\Doctrine\EntityManagerFactory;

it('derives the record labels from the query root and lets the setters win', function (): void {
    $entityManager = EntityManagerFactory::create();
    $songs = $entityManager->createQueryBuilder()->select('song')->from(Song::class, 'song');
    $derived = new TableHost(fn (Table $table): Table => $table->query($songs))->getTable();
    $custom = new TableHost(fn (Table $table): Table => $table->query($songs)->recordLabel('tune')->pluralRecordLabel(fn (): string => 'tunes!'))->getTable();
    $bare = new TableHost()->getTable();

    expect($derived->getRecordLabel())->toBe('song')
        ->and($derived->getPluralRecordLabel())->toBe('songs')
        ->and($custom->getRecordLabel())->toBe('tune')
        ->and($custom->getPluralRecordLabel())->toBe('tunes!')
        ->and($bare->getRecordLabel())->toBe('record')
        ->and($bare->getPluralRecordLabel())->toBe('records');
});

it('titles a record by closure, then by attribute, then by label', function (): void {
    $song = new Song(1, 'Airbag', 100);
    $byClosure = new TableHost(fn (Table $table): Table => $table->recordTitle(fn (Song $record): string => mb_strtoupper($record->getTitle())))->getTable();
    $byAttribute = new TableHost(fn (Table $table): Table => $table->recordTitleAttribute('title'))->getTable();
    $byMissingAttribute = new TableHost(fn (Table $table): Table => $table->recordTitleAttribute('nope'))->getTable();
    $bare = new TableHost()->getTable();

    expect($byClosure->getRecordTitle($song))->toBe('AIRBAG')
        ->and($byClosure->hasCustomRecordTitle())->toBeTrue()
        ->and($byAttribute->getRecordTitle($song))->toBe('Airbag')
        ->and($byAttribute->getRecordTitleAttribute())->toBe('title')
        ->and($byAttribute->hasCustomRecordTitle())->toBeFalse()
        ->and($byMissingAttribute->getRecordTitle($song))->toBe('record')
        ->and($bare->getRecordTitle($song))->toBe('record');
});
