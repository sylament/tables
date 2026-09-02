<?php

declare(strict_types=1);

use Sylament\Tables\Table;
use Sylament\Tables\Tests\Fixtures\Concerns\TableHost;

it('is unstriped until asked', function (): void {
    expect(new TableHost()->getTable()->isStriped())->toBeFalse();
});

it('stripes on the bare call, on a flag and through a closure', function (): void {
    $bare = new TableHost(fn (Table $table): Table => $table->striped())->getTable();
    $off = new TableHost(fn (Table $table): Table => $table->striped(false))->getTable();
    $byClosure = new TableHost(fn (Table $table): Table => $table
        ->striped(fn (Table $table): bool => $table->getRecordLabel() === 'record'))->getTable();

    expect($bare->isStriped())->toBeTrue()
        ->and($off->isStriped())->toBeFalse()
        ->and($byClosure->isStriped())->toBeTrue();
});
