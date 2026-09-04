<?php

declare(strict_types=1);

use Doctrine\ORM\QueryBuilder;
use Sylament\Tables\Columns\Summarizers\Summarizer;
use Sylament\Tables\Tests\Fixtures\Doctrine\Entity\Song;
use Sylament\Tables\Tests\Fixtures\Doctrine\EntityManagerFactory;

function summarizerQueryBuilder(): QueryBuilder
{
    return EntityManagerFactory::create()->createQueryBuilder()->select('song')->from(Song::class, 'song');
}

/*
 * One setter with two slots told apart by type: a builder points the summarizer at the
 * dataset the view is rendering, a closure scopes it — and the two never overwrite one
 * another, because the view re-points a scoped summarizer on every render.
 */
it('points at a query and scopes with a closure through one setter', function (): void {
    $query = summarizerQueryBuilder();
    $summarizer = Summarizer::make()->query($query);

    expect($summarizer->getQuery())->toBe($query)
        ->and($summarizer->hasQueryModification())->toBeFalse();

    $summarizer->query(fn (QueryBuilder $query): QueryBuilder => $query);

    expect($summarizer->getQuery())->toBe($query)
        ->and($summarizer->hasQueryModification())->toBeTrue();
});

it('clears both slots with null', function (): void {
    $summarizer = Summarizer::make()
        ->query(summarizerQueryBuilder())
        ->query(fn (QueryBuilder $query): QueryBuilder => $query)
        ->query(null);

    expect($summarizer->getQuery())->toBeNull()
        ->and($summarizer->hasQueryModification())->toBeFalse();
});

it('holds no query until it is pointed at one', function (): void {
    expect(Summarizer::make()->getQuery())->toBeNull()
        ->and(Summarizer::make()->hasQueryModification())->toBeFalse();
});
