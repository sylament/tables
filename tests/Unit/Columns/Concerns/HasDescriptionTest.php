<?php

declare(strict_types=1);

use Sylament\Tables\Columns\TextColumn;

it('has no description by default', function (): void {
    $column = TextColumn::make('title');

    expect($column->getDescriptionAbove())->toBeNull()
        ->and($column->getDescriptionBelow())->toBeNull()
        ->and($column->hasDescription())->toBeFalse();
});

it('defaults a description to the below position', function (): void {
    $column = TextColumn::make('title')->description('a note');

    expect($column->getDescriptionBelow())->toBe('a note')
        ->and($column->getDescriptionAbove())->toBeNull()
        ->and($column->hasDescription())->toBeTrue();
});

it('stores an above description and evaluates a closure with the record', function (): void {
    $column = TextColumn::make('title')
        ->record(['title' => 'Airbag', 'album' => 'OK Computer'])
        ->description(fn ($record): string => $record['album'], position: 'above');

    expect($column->getDescriptionAbove())->toBe('OK Computer')
        ->and($column->getDescriptionBelow())->toBeNull();
});
