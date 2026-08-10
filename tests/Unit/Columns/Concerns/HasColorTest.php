<?php

declare(strict_types=1);

use Sylament\Tables\Columns\TextColumn;
use Sylament\Tables\Tests\Fixtures\Records\Status;

it('has no color by default', function (): void {
    expect(TextColumn::make('status')->getColor('draft'))->toBeNull();
});

it('returns a literal color and evaluates a closure against the passed state', function (): void {
    expect(TextColumn::make('status')->color('danger')->getColor('draft'))->toBe('danger')
        ->and(TextColumn::make('status')->color(fn ($state): string => $state === 'draft' ? 'gray' : 'success')->getColor('draft'))->toBe('gray')
        ->and(TextColumn::make('status')->color(fn ($state): string => $state === 'draft' ? 'gray' : 'success')->getColor('live'))->toBe('success');
});

it('treats false as no color and suppresses the state contract', function (): void {
    expect(TextColumn::make('status')->color(false)->getColor(Status::Published))->toBeNull();
});

it('throws when the color evaluates to true, naming the column', function (): void {
    expect(fn (): string|array|null => TextColumn::make('status')->color(true)->getColor('draft'))
        ->toThrow(LogicException::class, '[status]');
});

it('falls back to a state implementing the color contract', function (): void {
    expect(TextColumn::make('status')->getColor(Status::Published))->toBe('success')
        ->and(TextColumn::make('status')->getColor(Status::Draft))->toBe('gray');
});

it('resolves a colors map by bare default, by matching state, and by predicate', function (): void {
    $column = TextColumn::make('status')->colors([
        'gray',
        'success' => 'published',
        'danger' => fn ($state): bool => $state === 'retracted',
    ]);

    expect($column->getColor('anything'))->toBe('gray')
        ->and($column->getColor('published'))->toBe('success')
        ->and($column->getColor('retracted'))->toBe('danger');
});

it('lets the last matching colors entry win', function (): void {
    $column = TextColumn::make('status')->colors([
        'success' => 'published',
        'warning' => 'published',
    ]);

    expect($column->getColor('published'))->toBe('warning');
});
