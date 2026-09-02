<?php

declare(strict_types=1);

use Sylament\Tables\Table;
use Sylament\Tables\Tests\Fixtures\Concerns\TableHost;

it('loads eagerly until asked to defer', function (): void {
    $host = new TableHost();

    expect($host->getTable()->isLoadingDeferred())->toBeFalse()
        ->and($host->getTable()->isLoaded())->toBeTrue()
        ->and($host->isTableLoaded)->toBeFalse();
});

it('defers on the bare call, on a flag and through a closure', function (): void {
    $bare = new TableHost(fn (Table $table): Table => $table->deferLoading())->getTable();
    $off = new TableHost(fn (Table $table): Table => $table->deferLoading(false))->getTable();
    $byClosure = new TableHost(fn (Table $table): Table => $table
        ->deferLoading(fn (Table $table): bool => $table->getRecordLabel() === 'record'))->getTable();

    expect($bare->isLoadingDeferred())->toBeTrue()
        ->and($off->isLoadingDeferred())->toBeFalse()
        ->and($byClosure->isLoadingDeferred())->toBeTrue();
});

it('reads the loaded state off the host while deferred', function (): void {
    $host = new TableHost(fn (Table $table): Table => $table->deferLoading());

    expect($host->getTable()->isLoaded())->toBeFalse();

    $host->loadTable();

    expect($host->isTableLoaded)->toBeTrue()
        ->and($host->getTable()->isLoaded())->toBeTrue();
});

it('short-circuits the host flag when nothing is deferred', function (): void {
    $host = new TableHost();

    expect($host->isTableLoaded)->toBeFalse()
        ->and($host->isTableLoaded())->toBeTrue();
});
