<?php

declare(strict_types=1);

use Sylament\Tables\Columns\TextColumn;

it('passes state through unformatted by default', function (): void {
    expect(TextColumn::make('title')->record(['title' => 'Airbag'])->getFormattedState())->toBe('Airbag');
});

it('formats state with the state and record injected', function (): void {
    $column = TextColumn::make('status')
        ->record(['status' => 'draft', 'title' => 'Airbag'])
        ->formatStateUsing(fn (string $state, $record): string => mb_strtoupper($state) . ' — ' . $record['title']);

    expect($column->getFormattedState())->toBe('DRAFT — Airbag');
});

it('accepts a literal for formatStateUsing', function (): void {
    expect(TextColumn::make('status')->record(['status' => 'draft'])->formatStateUsing('fixed')->getFormattedState())->toBe('fixed');
});
