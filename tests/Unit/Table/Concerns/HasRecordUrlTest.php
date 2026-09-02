<?php

declare(strict_types=1);

use Sylament\Tables\Table;
use Sylament\Tables\Tests\Fixtures\Concerns\TableHost;
use Sylament\Tables\Tests\Fixtures\Doctrine\Entity\Song;

it('answers no record url until one is set', function (): void {
    $table = new TableHost()->getTable();

    expect($table->getRecordUrl(new Song(1, 'Airbag', 100)))->toBeNull()
        ->and($table->hasCustomRecordUrl())->toBeFalse();
});

it('injects the record by name and by class into the url closure', function (): void {
    $byName = new TableHost(fn (Table $table): Table => $table->recordUrl(
        fn ($record): string => '/songs/' . $record->getId(),
    ))->getTable();
    $byType = new TableHost(fn (Table $table): Table => $table->recordUrl(
        fn (Song $song, Table $table): string => '/songs/' . $song->getId() . '/' . $table->getRecordLabel(),
    ))->getTable();

    expect($byName->getRecordUrl(new Song(7, 'Airbag', 100)))->toBe('/songs/7')
        ->and($byName->hasCustomRecordUrl())->toBeTrue()
        ->and($byType->getRecordUrl(new Song(7, 'Airbag', 100)))->toBe('/songs/7/record');
});

it('takes an array record by name alone', function (): void {
    $table = new TableHost(fn (Table $table): Table => $table->recordUrl(
        fn (array $record): string => '/songs/' . $record['id'],
    ))->getTable();

    expect($table->getRecordUrl(['id' => 3]))->toBe('/songs/3');
});

it('sanitizes the url it answers', function (): void {
    $table = new TableHost(fn (Table $table): Table => $table->recordUrl('javascript:alert(1)'))->getTable();

    expect($table->getRecordUrl(new Song(1, 'Airbag', 100)))->toBeNull();
});

it('opens the url in a new tab per record', function (): void {
    $record = new Song(1, 'Airbag', 100);
    $default = new TableHost(fn (Table $table): Table => $table->recordUrl('/songs/1'))->getTable();
    $positional = new TableHost(fn (Table $table): Table => $table->recordUrl('/songs/1', true))->getTable();
    $setter = new TableHost(fn (Table $table): Table => $table->openRecordUrlInNewTab())->getTable();
    $byClosure = new TableHost(fn (Table $table): Table => $table
        ->openRecordUrlInNewTab(fn (Song $record): bool => $record->getId() === 1))->getTable();

    expect($default->shouldOpenRecordUrlInNewTab($record))->toBeFalse()
        ->and($positional->shouldOpenRecordUrlInNewTab($record))->toBeTrue()
        ->and($setter->shouldOpenRecordUrlInNewTab($record))->toBeTrue()
        ->and($byClosure->shouldOpenRecordUrlInNewTab($record))->toBeTrue()
        ->and($byClosure->shouldOpenRecordUrlInNewTab(new Song(2, 'Bones', 100)))->toBeFalse();
});

it('leaves the new-tab flag alone when recordUrl passes null for it', function (): void {
    $table = new TableHost(fn (Table $table): Table => $table
        ->openRecordUrlInNewTab()
        ->recordUrl('/songs/1'))->getTable();

    expect($table->shouldOpenRecordUrlInNewTab(new Song(1, 'Airbag', 100)))->toBeTrue();
});

it('replaces the extra link attributes unless asked to merge', function (): void {
    $record = new Song(1, 'Airbag', 100);
    $replaced = new TableHost(fn (Table $table): Table => $table
        ->extraRecordLinkAttributes(['data-first' => 'a'])
        ->extraRecordLinkAttributes(['data-second' => 'b']))->getTable();
    $merged = new TableHost(fn (Table $table): Table => $table
        ->extraRecordLinkAttributes(['data-first' => 'a', 'class' => 'one'])
        ->extraRecordLinkAttributes(fn (Song $record): array => ['data-second' => $record->getTitle(), 'class' => 'two'], merge: true))->getTable();

    expect($replaced->getExtraRecordLinkAttributes($record))->toBe(['data-second' => 'b'])
        ->and($merged->getExtraRecordLinkAttributes($record))->toBe([
            'data-second' => 'Airbag',
            'class' => ['two', 'one'],
            'data-first' => 'a',
        ]);
});
