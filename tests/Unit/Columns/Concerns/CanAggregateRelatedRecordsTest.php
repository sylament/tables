<?php

declare(strict_types=1);

use Sylament\Tables\Columns\TextColumn;
use Sylament\Tables\Query\AggregateDeclaration;

it('reports no aggregates by default', function (): void {
    $column = TextColumn::make('title');

    expect($column->hasRelationshipAggregates())->toBeFalse()
        ->and($column->getCellAggregateDeclaration())->toBeNull();
});

it('resolves the single counts declaration to the column name regardless of that name', function (): void {
    $column = TextColumn::make('anything')->counts('songs');

    $declaration = $column->getCellAggregateDeclaration();

    expect($column->hasRelationshipAggregates())->toBeTrue()
        ->and($declaration?->function)->toBe('COUNT')
        ->and($declaration?->relationshipPath)->toBe('songs')
        ->and($declaration?->column)->toBeNull()
        ->and($declaration?->scope)->toBeNull()
        ->and($declaration?->castToBool)->toBeFalse();
});

it('resolves avg with its column', function (): void {
    $declaration = TextColumn::make('anything')->avg('songs', 'duration')->getCellAggregateDeclaration();

    expect($declaration?->function)->toBe('AVG')
        ->and($declaration?->relationshipPath)->toBe('songs')
        ->and($declaration?->column)->toBe('duration')
        ->and($declaration?->castToBool)->toBeFalse();
});

it('marks exists as bool-cast COUNT', function (): void {
    $declaration = TextColumn::make('anything')->exists('songs')->getCellAggregateDeclaration();

    expect($declaration?->function)->toBe('COUNT')
        ->and($declaration?->relationshipPath)->toBe('songs')
        ->and($declaration?->column)->toBeNull()
        ->and($declaration?->castToBool)->toBeTrue();
});

it('maps each remaining aggregate kind to its SQL function', function (): void {
    expect(TextColumn::make('a')->max('songs', 'duration')->getCellAggregateDeclaration()?->function)->toBe('MAX')
        ->and(TextColumn::make('a')->min('songs', 'duration')->getCellAggregateDeclaration()?->function)->toBe('MIN')
        ->and(TextColumn::make('a')->sum('songs', 'duration')->getCellAggregateDeclaration()?->function)->toBe('SUM');
});

it('evaluates a closure declaration through the getters', function (): void {
    $column = TextColumn::make('anything')
        ->avg(fn (): string => 'songs', fn (): string => 'duration');

    $declaration = $column->getCellAggregateDeclaration();

    expect($column->getRelationshipToAvg())->toBe('songs')
        ->and($column->getColumnToAvg())->toBe('duration')
        ->and($declaration?->relationshipPath)->toBe('songs')
        ->and($declaration?->column)->toBe('duration');
});

it('array form picks the entry whose derived alias matches the column name', function (): void {
    $declaration = TextColumn::make('songs_count')->counts(['songs', 'genres'])->getCellAggregateDeclaration();

    expect($declaration?->relationshipPath)->toBe('songs')
        ->and($declaration?->function)->toBe('COUNT');

    $aliased = TextColumn::make('mySongs')->counts(['songs as mySongs'])->getCellAggregateDeclaration();

    expect($aliased?->relationshipPath)->toBe('songs');

    $withColumn = TextColumn::make('songs_avg_duration')->avg(['songs', 'genres'], 'duration')->getCellAggregateDeclaration();

    expect($withColumn?->relationshipPath)->toBe('songs')
        ->and($withColumn?->column)->toBe('duration');
});

it('derives the alias from the snake case of a camel case relationship', function (): void {
    $declaration = TextColumn::make('my_songs_count')->counts(['mySongs', 'genres'])->getCellAggregateDeclaration();

    expect($declaration?->relationshipPath)->toBe('mySongs');
});

it('array form with a Closure value carries it as the scope', function (): void {
    $scope = static fn (object $query): object => $query;

    $declaration = TextColumn::make('songs_count')->counts(['songs' => $scope])->getCellAggregateDeclaration();

    expect($declaration?->relationshipPath)->toBe('songs')
        ->and($declaration?->scope)->toBe($scope);
});

it('array form with no matching alias resolves to null', function (): void {
    expect(TextColumn::make('unrelated')->counts(['songs', 'genres'])->getCellAggregateDeclaration())->toBeNull()
        ->and(TextColumn::make('unrelated')->counts(['songs'])->getCellAggregateDeclaration())->toBeNull();
});

it('resolves the string form beside an array form that names another cell', function (): void {
    $declaration = TextColumn::make('anything')
        ->counts('songs')
        ->avg(['songs'], 'duration')
        ->getCellAggregateDeclaration();

    expect($declaration?->function)->toBe('COUNT')
        ->and($declaration?->relationshipPath)->toBe('songs');
});

it('leaves two array forms that name no cell inert', function (): void {
    $column = TextColumn::make('title')
        ->counts(['songs'])
        ->sum(['plays'], 'count');

    expect($column->getCellAggregateDeclaration())->toBeNull()
        ->and($column->hasRelationshipAggregates())->toBeTrue();
});

it('refuses a string form beside an array entry aliased to the column name', function (): void {
    $column = TextColumn::make('total_plays')
        ->counts('songs')
        ->sum(['plays as total_plays'], 'count');

    expect(fn (): ?AggregateDeclaration => $column->getCellAggregateDeclaration())
        ->toThrow(LogicException::class, 'declares multiple aggregates');
});

it('a column declaring two aggregate kinds resolves the one matching its name and otherwise refuses', function (): void {
    $matching = TextColumn::make('songs_count')->counts('songs')->avg('songs', 'duration');

    expect($matching->getCellAggregateDeclaration()?->function)->toBe('COUNT');

    $refusing = TextColumn::make('unrelated')->counts('songs')->avg('songs', 'duration');

    expect(fn (): ?AggregateDeclaration => $refusing->getCellAggregateDeclaration())
        ->toThrow(LogicException::class, 'declares multiple aggregates');
});

it('refuses an array entry that names no relationship', function (): void {
    expect(fn (): ?AggregateDeclaration => TextColumn::make('songs_count')->counts([static fn (object $query): object => $query])->getCellAggregateDeclaration())
        ->toThrow(LogicException::class, '[songs_count]');
});
