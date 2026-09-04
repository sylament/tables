<?php

declare(strict_types=1);

use Sylament\Tables\Columns\Column;

it('has no url and opens in the same tab by default', function (): void {
    $column = Column::make('title');

    expect($column->getUrl())->toBeNull()
        ->and($column->hasStateBasedUrls())->toBeFalse()
        ->and($column->shouldOpenUrlInNewTab())->toBeFalse();
});

it('answers a literal url from the zero-argument call', function (): void {
    expect(Column::make('title')->url('/songs/1')->getUrl())->toBe('/songs/1');
});

it('takes the new-tab flag from the second url argument and leaves an earlier one alone when it is null', function (): void {
    expect(Column::make('title')->url('/songs', shouldOpenInNewTab: true)->shouldOpenUrlInNewTab())->toBeTrue()
        ->and(Column::make('title')->openUrlInNewTab()->url('/songs')->shouldOpenUrlInNewTab())->toBeTrue()
        ->and(Column::make('title')->openUrlInNewTab()->url('/songs', shouldOpenInNewTab: false)->shouldOpenUrlInNewTab())->toBeFalse();
});

it('detects a state-based url by the closure naming a state parameter', function (): void {
    expect(Column::make('title')->url(fn (mixed $state): string => '/songs/' . $state)->hasStateBasedUrls())->toBeTrue()
        ->and(Column::make('title')->url(fn (): string => '/songs')->hasStateBasedUrls())->toBeFalse()
        ->and(Column::make('title')->url('/songs')->hasStateBasedUrls())->toBeFalse();
});

it('switches on the argument count so each arity answers only its own kind of url', function (): void {
    $stateBased = Column::make('title')->url(fn (mixed $state): string => '/songs/' . $state);
    $plain = Column::make('title')->url('/songs');

    expect($stateBased->getUrl())->toBeNull()
        ->and($stateBased->getUrl('7'))->toBe('/songs/7')
        ->and($plain->getUrl())->toBe('/songs')
        ->and($plain->getUrl('7'))->toBeNull();
});

it('sanitizes both arities and answers null for a refused scheme', function (): void {
    expect(Column::make('title')->url('javascript:alert(1)')->getUrl())->toBeNull()
        ->and(Column::make('title')->url(fn (mixed $state): string => 'javascript:alert(' . $state . ')')->getUrl('1'))->toBeNull();
});

it('evaluates the new-tab condition as a closure', function (): void {
    expect(Column::make('title')->openUrlInNewTab(fn (Column $column): bool => $column->getName() === 'title')->shouldOpenUrlInNewTab())->toBeTrue();
});
