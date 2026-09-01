<?php

declare(strict_types=1);

use Sylament\Tables\Table;
use Sylament\Tables\Tests\Fixtures\Concerns\TableHost;

it('derives h2 from the default root heading level', function (): void {
    $table = Table::make(new TableHost());

    expect($table->getRootHeadingLevel())->toBe(2)
        ->and($table->getHeadingLevel())->toBe(2)
        ->and($table->getHeadingTag())->toBe('h2');
});

it('offsets the second-level tag by the index', function (): void {
    $table = Table::make(new TableHost());

    expect($table->getHeadingLevel(1))->toBe(3)
        ->and($table->getHeadingTag(1))->toBe('h3');
});

it('moves the whole ladder with rootHeadingLevel()', function (): void {
    $table = Table::make(new TableHost())->rootHeadingLevel(3);

    expect($table->getHeadingTag())->toBe('h3')
        ->and($table->getHeadingTag(1))->toBe('h4');
});

it('falls to a paragraph past h6', function (): void {
    $table = Table::make(new TableHost())->rootHeadingLevel(6);

    expect($table->getHeadingTag())->toBe('h6')
        ->and($table->getHeadingTag(1))->toBe('p');
});
