<?php

declare(strict_types=1);

use Sylament\Tables\Columns\TextColumn;
use Sylament\Tables\Contracts\InteractsWithTable;
use Sylament\Tables\Table;
use Sylament\Tables\Tests\Fixtures\Concerns\TableHost;
use Twig\Markup;

it('binds the live component on make', function (): void {
    $host = new TableHost();

    $table = Table::make($host);

    expect($table->getLiveComponent())->toBe($host);
});

it('stores and returns the records data source', function (): void {
    $dataSource = fn (): array => [];

    $table = Table::make(new TableHost())->records($dataSource);

    expect($table->getDataSource())->toBe($dataSource);
});

it('delegates the record key to the host', function (): void {
    $table = Table::make(new TableHost());

    expect($table->getRecordKey(['__key' => '7']))->toBe('7');
});

it('defaults the empty state heading and leaves description and icon unset', function (): void {
    $table = Table::make(new TableHost());

    expect($table->getEmptyStateHeading())->toBe('No records')
        ->and($table->getEmptyStateDescription())->toBeNull()
        ->and($table->getEmptyStateIcon())->toBeNull();
});

it('passes a markup empty state icon through untouched', function (): void {
    $icon = new Markup('<svg data-icon="empty"></svg>', 'UTF-8');

    $table = Table::make(new TableHost())->emptyStateIcon($icon);

    expect($table->getEmptyStateIcon())->toBe($icon);
});

it('evaluates empty state values with table and live component injections', function (): void {
    $host = new TableHost();

    $table = Table::make($host)
        ->emptyStateHeading(fn ($liveComponent): string => $liveComponent instanceof InteractsWithTable ? 'from host' : 'wrong')
        ->emptyStateDescription(fn (Table $table): string => $table->getEmptyStateHeading());

    expect($table->getEmptyStateHeading())->toBe('from host')
        ->and($table->getEmptyStateDescription())->toBe('from host');
});

it('keys columns by name and binds itself to each', function (): void {
    $table = Table::make(new TableHost());
    $title = TextColumn::make('title');

    $table->columns([$title, TextColumn::make('slug')]);

    expect(array_keys($table->getColumns()))->toBe(['title', 'slug'])
        ->and($table->getColumn('title'))->toBe($title)
        ->and($title->getTable())->toBe($table);
});

it('resets previously declared columns on a second columns() call', function (): void {
    $table = Table::make(new TableHost())
        ->columns([TextColumn::make('title')])
        ->columns([TextColumn::make('slug')]);

    expect(array_keys($table->getColumns()))->toBe(['slug']);
});
