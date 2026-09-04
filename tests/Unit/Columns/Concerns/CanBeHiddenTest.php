<?php

declare(strict_types=1);

use Sylament\Tables\Columns\TextColumn;
use Sylament\Tables\Table;
use Sylament\Tables\Tests\Fixtures\Concerns\BareTableHost;
use Sylament\Tables\Tests\Fixtures\Concerns\TableHost;

it('is visible by default and hidden wins over visible', function (): void {
    $column = TextColumn::make('title');

    expect($column->isHidden())->toBeFalse()
        ->and($column->isVisible())->toBeTrue()
        ->and(TextColumn::make('title')->hidden()->isHidden())->toBeTrue()
        ->and(TextColumn::make('title')->hidden()->visible()->isHidden())->toBeTrue()
        ->and(TextColumn::make('slug')->visible(false)->isHidden())->toBeTrue()
        ->and(TextColumn::make('slug')->hidden(fn (): bool => true)->isVisible())->toBeFalse();
});

it('hides on the named host and not on another', function (): void {
    $column = TextColumn::make('title')->hiddenOn(TableHost::class);
    Table::make(new TableHost())->columns([$column]);

    expect($column->isHidden())->toBeTrue();

    $other = TextColumn::make('title')->hiddenOn(BareTableHost::class);
    Table::make(new TableHost())->columns([$other]);

    expect($other->isHidden())->toBeFalse();
});

it('shows on the named host and not on another', function (): void {
    $column = TextColumn::make('title')->visibleOn([TableHost::class]);
    Table::make(new TableHost())->columns([$column]);

    expect($column->isVisible())->toBeTrue();

    $other = TextColumn::make('title')->visibleOn([BareTableHost::class]);
    Table::make(new TableHost())->columns([$other]);

    expect($other->isVisible())->toBeFalse();
});
