<?php

declare(strict_types=1);

use Sylament\Tables\Table;
use Sylament\Tables\Tests\Fixtures\Concerns\TableHost;

it('holds no heading and no description by default', function (): void {
    $table = Table::make(new TableHost());

    expect($table->getHeading())->toBeNull()
        ->and($table->getDescription())->toBeNull();
});

it('evaluates a literal heading and description', function (): void {
    $table = Table::make(new TableHost())
        ->heading('Sections')
        ->description('The rows this article holds.');

    expect($table->getHeading())->toBe('Sections')
        ->and($table->getDescription())->toBe('The rows this article holds.');
});

it('evaluates closure arms with the table injection', function (): void {
    $table = Table::make(new TableHost())
        ->heading(fn (Table $table): string => 'Heading over ' . count($table->getColumns()) . ' columns')
        ->description(fn (): string => 'closured');

    expect($table->getHeading())->toBe('Heading over 0 columns')
        ->and($table->getDescription())->toBe('closured');
});

it('clears both with null', function (): void {
    $table = Table::make(new TableHost())
        ->heading('Sections')
        ->description('gone soon')
        ->heading(null)
        ->description(null);

    expect($table->getHeading())->toBeNull()
        ->and($table->getDescription())->toBeNull();
});
