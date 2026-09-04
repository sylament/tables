<?php

declare(strict_types=1);

use Sylament\Tables\Columns\Column;

it('carries no row loop by default and clears back to null', function (): void {
    expect(Column::make('title')->getRowLoop())->toBeNull()
        ->and(Column::make('title')->rowLoop(['index' => 1])->rowLoop(null)->getRowLoop())->toBeNull();
});

it('translates a countable twig loop into blade vocabulary', function (): void {
    $loop = Column::make('title')->rowLoop([
        'index0' => 1,
        'index' => 2,
        'revindex0' => 1,
        'revindex' => 2,
        'first' => false,
        'last' => false,
        'length' => 3,
    ])->getRowLoop();

    expect($loop)->toBeInstanceOf(stdClass::class)
        ->and($loop?->iteration)->toBe(2)
        ->and($loop?->index)->toBe(1)
        ->and($loop?->remaining)->toBe(1)
        ->and($loop?->count)->toBe(3)
        ->and($loop?->first)->toBeFalse()
        ->and($loop?->last)->toBeFalse()
        ->and($loop?->even)->toBeTrue()
        ->and($loop?->odd)->toBeFalse()
        ->and($loop?->depth)->toBe(1)
        ->and($loop?->parent)->toBeNull();
});

it('reads odd and even off the one-based iteration', function (): void {
    $first = Column::make('title')->rowLoop(['index0' => 0, 'index' => 1, 'first' => true])->getRowLoop();

    expect($first?->odd)->toBeTrue()
        ->and($first?->even)->toBeFalse();
});

it('leaves the countable-only entries null for a loop that carries none', function (): void {
    $loop = Column::make('title')->rowLoop(['index0' => 0, 'index' => 1, 'first' => true])->getRowLoop();

    expect($loop?->count)->toBeNull()
        ->and($loop?->remaining)->toBeNull()
        ->and($loop?->last)->toBeNull();
});

it('never copies twigs parent entry, which holds the whole parent context', function (): void {
    $loop = Column::make('title')->rowLoop(['index0' => 0, 'index' => 1, 'parent' => ['records' => ['a', 'b']]])->getRowLoop();

    expect($loop?->parent)->toBeNull();
});

it('passes an object through untranslated', function (): void {
    $object = (object) ['iteration' => 9];

    expect(Column::make('title')->rowLoop($object)->getRowLoop())->toBe($object);
});
