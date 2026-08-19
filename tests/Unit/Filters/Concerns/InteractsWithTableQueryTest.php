<?php

declare(strict_types=1);

use Doctrine\ORM\QueryBuilder;
use Sylament\Tables\Filters\BaseFilter;
use Sylament\Tables\Tests\Fixtures\Doctrine\Entity\Song;
use Sylament\Tables\Tests\Fixtures\Doctrine\EntityManagerFactory;

function filterQuery(): QueryBuilder
{
    return EntityManagerFactory::create()->createQueryBuilder()->select('song')->from(Song::class, 'song');
}

it('does nothing without a query callback', function (): void {
    $query = filterQuery();

    expect(BaseFilter::make('status')->apply($query))->toBe($query)
        ->and($query->getDQLPart('where'))->toBeNull();
});

it('does nothing without a base query callback', function (): void {
    $query = filterQuery();

    expect(BaseFilter::make('status')->applyToBaseQuery($query))->toBe($query)
        ->and($query->getDQLPart('where'))->toBeNull();
});

it('skips a hidden filter that has a callback', function (): void {
    $query = filterQuery();

    BaseFilter::make('status')
        ->hidden()
        ->query(fn (QueryBuilder $query): QueryBuilder => $query->andWhere('song.title = :t'))
        ->apply($query);

    expect($query->getDQLPart('where'))->toBeNull();
});

it('skips a hidden filter on the base pass too', function (): void {
    $query = filterQuery();

    BaseFilter::make('status')
        ->hidden()
        ->baseQuery(fn (QueryBuilder $query): QueryBuilder => $query->andWhere('song.title = :t'))
        ->applyToBaseQuery($query);

    expect($query->getDQLPart('where'))->toBeNull();
});

it('treats isActive as an opt-out, not an opt-in', function (): void {
    $callback = fn (QueryBuilder $query): QueryBuilder => $query->andWhere('song.title = :t');

    $absent = filterQuery();
    BaseFilter::make('status')->query($callback)->apply($absent, ['value' => 'x']);

    $inactive = filterQuery();
    BaseFilter::make('status')->query($callback)->apply($inactive, ['isActive' => false]);

    $active = filterQuery();
    BaseFilter::make('status')->query($callback)->apply($active, ['isActive' => true]);

    expect((string) $absent->getDQLPart('where'))->toBe('song.title = :t')
        ->and($inactive->getDQLPart('where'))->toBeNull()
        ->and((string) $active->getDQLPart('where'))->toBe('song.title = :t');
});

it('treats isActive as an opt-out on the base pass too', function (): void {
    $callback = fn (QueryBuilder $query): QueryBuilder => $query->andWhere('song.title = :t');

    $absent = filterQuery();
    BaseFilter::make('status')->baseQuery($callback)->applyToBaseQuery($absent, ['value' => 'x']);

    $inactive = filterQuery();
    BaseFilter::make('status')->baseQuery($callback)->applyToBaseQuery($inactive, ['isActive' => false]);

    expect((string) $absent->getDQLPart('where'))->toBe('song.title = :t')
        ->and($inactive->getDQLPart('where'))->toBeNull();
});

it('injects data, query and state, and discards the closure return', function (): void {
    $query = filterQuery();
    $seen = [];

    $returned = BaseFilter::make('status')
        ->query(function (array $data, array $state, QueryBuilder $query) use (&$seen): string {
            $seen = [$data, $state];
            $query->andWhere('song.title = :t');

            return 'discarded';
        })
        ->apply($query, ['value' => 'published']);

    expect($returned)->toBe($query)
        ->and($seen)->toBe([['value' => 'published'], ['value' => 'published']])
        ->and((string) $query->getDQLPart('where'))->toBe('song.title = :t');
});

it('runs the base pass through its own callback', function (): void {
    $query = filterQuery();

    BaseFilter::make('status')
        ->baseQuery(fn (QueryBuilder $query): QueryBuilder => $query->andWhere('song.plays = :p'))
        ->applyToBaseQuery($query, ['isActive' => true]);

    expect((string) $query->getDQLPart('where'))->toBe('song.plays = :p')
        ->and($query->setParameter('p', 10)->getQuery()->getResult())->toBe([]);

    $apply = filterQuery();
    BaseFilter::make('status')
        ->baseQuery(fn (QueryBuilder $query): QueryBuilder => $query->andWhere('song.plays = :p'))
        ->apply($apply, ['isActive' => true]);

    expect($apply->getDQLPart('where'))->toBeNull();
});

it('reads the record-resolution opt-out', function (): void {
    expect(BaseFilter::make('status')->shouldExcludeWhenResolvingRecord())->toBeFalse()
        ->and(BaseFilter::make('status')->excludeWhenResolvingRecord()->shouldExcludeWhenResolvingRecord())->toBeTrue()
        ->and(BaseFilter::make('status')->excludeWhenResolvingRecord(fn (): bool => true)->shouldExcludeWhenResolvingRecord())->toBeTrue();
});
