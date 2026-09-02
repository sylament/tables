<?php

declare(strict_types=1);

use Sylament\Tables\Table;
use Sylament\Tables\Tests\Fixtures\Concerns\TableHost;

/**
 * A table configured by one closure, built outside the container.
 */
function polledTable(?Closure $configure = null): Table
{
    return new TableHost($configure)->getTable();
}

it('does not poll until asked', function (): void {
    expect(polledTable()->getPollingInterval())->toBeNull()
        ->and(polledTable()->getPollingIntervalMilliseconds())->toBeNull();
});

it('polls every ten seconds on the bare call', function (): void {
    $table = polledTable(fn (Table $table): Table => $table->poll());

    expect($table->getPollingInterval())->toBe('10s')
        ->and($table->getPollingIntervalMilliseconds())->toBe(10000);
});

it('takes an interval literally, through a closure and back off again', function (): void {
    expect(polledTable(fn (Table $table): Table => $table->poll('500ms'))->getPollingInterval())->toBe('500ms')
        ->and(polledTable(fn (Table $table): Table => $table->poll(fn (): string => '30s'))->getPollingInterval())->toBe('30s')
        ->and(polledTable(fn (Table $table): Table => $table->poll()->poll(null))->getPollingInterval())->toBeNull();
});

it('translates both duration units into milliseconds', function (string $interval, int $milliseconds): void {
    expect(polledTable(fn (Table $table): Table => $table->poll($interval))->getPollingIntervalMilliseconds())->toBe($milliseconds);
})->with([
    ['1s', 1000],
    ['10s', 10000],
    ['0.5s', 500],
    ['750ms', 750],
    [' 2s ', 2000],
]);

it('refuses an interval it cannot parse', function (string $interval): void {
    expect(fn (): ?int => polledTable(fn (Table $table): Table => $table->poll($interval))->getPollingIntervalMilliseconds())
        ->toThrow(InvalidArgumentException::class);
})->with([
    ['10'],
    ['soon'],
    [''],
    ['10 seconds'],
    ['-5s'],
    ['5m'],
]);
