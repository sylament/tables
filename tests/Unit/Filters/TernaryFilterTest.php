<?php

declare(strict_types=1);

use Doctrine\ORM\EntityManager;
use Doctrine\ORM\QueryBuilder;
use Sylament\Forms\Components\Select;
use Sylament\Tables\Filters\TernaryFilter;
use Sylament\Tables\Table;
use Sylament\Tables\Tests\Fixtures\Concerns\FilterStateHost;
use Sylament\Tables\Tests\Fixtures\Doctrine\Entity\Song;
use Sylament\Tables\Tests\Fixtures\Doctrine\EntityManagerFactory;

/*
 * The ternary filter: the boolean select it builds on top of the SelectFilter
 * chain, the int-cast state, the three-arm query dispatcher, and the two arm
 * sets it ships — boolean equality and nullability.
 */

function ternaryFilterSongs(): EntityManager
{
    $entityManager = EntityManagerFactory::create();

    EntityManagerFactory::seed(
        $entityManager,
        new Song(1, 'Alpha', 10, isFeatured: true),
        new Song(2, 'Bravo', 20, isFeatured: false),
        new Song(3, 'Charlie', 30),
    );

    return $entityManager;
}

function ternaryFilterQuery(EntityManager $entityManager): QueryBuilder
{
    return $entityManager->createQueryBuilder()->select('song')->from(Song::class, 'song');
}

/**
 * @return list<string>
 */
function ternaryFilterTitles(QueryBuilder $query): array
{
    $titles = [];

    foreach ($query->getQuery()->getResult() as $song) {
        $titles[] = $song->getTitle();
    }

    return $titles;
}

it('builds the boolean select from the parent chain with the default arm labels', function (): void {
    $field = TernaryFilter::make('isFeatured')->label('Featured')->getFormField();

    expect($field)->toBeInstanceOf(Select::class)
        ->and($field->getName())->toBe('value')
        ->and($field->getLabel())->toBe('Featured')
        ->and($field->getOptions())->toBe([1 => 'Yes', 0 => 'No'])
        ->and($field->getPlaceholder())->toBe('-');
});

it('carries custom ternary labels and placeholder into the boolean options', function (): void {
    $field = TernaryFilter::make('isFeatured')
        ->trueLabel('Featured')
        ->falseLabel('Plain')
        ->placeholder('Any')
        ->getFormField();

    expect($field->getOptions())->toBe([1 => 'Featured', 0 => 'Plain'])
        ->and($field->getPlaceholder())->toBe('Any')
        ->and(TernaryFilter::make('a')->trueLabel('Featured')->getTrueLabel())->toBe('Featured')
        ->and(TernaryFilter::make('b')->falseLabel('Plain')->getFalseLabel())->toBe('Plain');
});

it('casts a boolean default to the int the state speaks', function (): void {
    expect(TernaryFilter::make('a')->default(true)->getDefaultState())->toBe(1)
        ->and(TernaryFilter::make('b')->default(false)->getDefaultState())->toBe(0)
        ->and(TernaryFilter::make('c')->default(null)->getDefaultState())->toBeNull()
        ->and(TernaryFilter::make('d')->default(fn (): bool => true)->getDefaultState())->toBe(1)
        ->and(TernaryFilter::make('e')->getFormField()->getDefaultState())->toBeNull()
        ->and(TernaryFilter::make('f')->default(false)->getFormField()->getDefaultState())->toBe(0);
});

it('dispatches the three ternary arms', function (): void {
    $query = ternaryFilterQuery(ternaryFilterSongs());
    $calls = [];

    $filter = TernaryFilter::make('isFeatured')->queries(
        true: function (QueryBuilder $query) use (&$calls): void {
            $calls[] = 'true';
        },
        false: function (QueryBuilder $query) use (&$calls): void {
            $calls[] = 'false';
        },
        blank: function (QueryBuilder $query) use (&$calls): void {
            $calls[] = 'blank';
        },
    );

    $filter->apply($query, ['value' => 1]);
    $filter->apply($query, ['value' => 0]);
    $filter->apply($query, ['value' => null]);
    $filter->apply($query, []);

    expect($calls)->toBe(['true', 'false', 'blank', 'blank']);
});

it('passes the query through when the blank arm is omitted', function (): void {
    $query = ternaryFilterQuery(ternaryFilterSongs());

    TernaryFilter::make('isFeatured')->queries(
        true: function (QueryBuilder $query): void {
            $query->andWhere('song.plays > 15');
        },
        false: function (QueryBuilder $query): void {
            $query->andWhere('song.plays < 15');
        },
    )->apply($query, ['value' => null]);

    expect($query->getDQL())->not->toContain('WHERE')
        ->and(ternaryFilterTitles($query))->toBe(['Alpha', 'Bravo', 'Charlie']);
});

it('narrows on the boolean column with the root alias qualified', function (): void {
    $entityManager = ternaryFilterSongs();
    $true = ternaryFilterQuery($entityManager);
    $false = ternaryFilterQuery($entityManager);
    $blank = ternaryFilterQuery($entityManager);

    TernaryFilter::make('isFeatured')->apply($true, ['value' => 1]);
    TernaryFilter::make('isFeatured')->apply($false, ['value' => 0]);
    TernaryFilter::make('isFeatured')->apply($blank, ['value' => null]);

    expect($true->getDQL())->toContain('WHERE song.isFeatured = :sylament_filter_0')
        ->and($true->getParameter('sylament_filter_0')?->getValue())->toBeTrue()
        ->and($false->getParameter('sylament_filter_0')?->getValue())->toBeFalse()
        ->and(ternaryFilterTitles($true))->toBe(['Alpha'])
        ->and(ternaryFilterTitles($false))->toBe(['Bravo'])
        ->and($blank->getDQL())->not->toContain('WHERE');
});

it('narrows on nullability when nullable is chosen', function (): void {
    $entityManager = ternaryFilterSongs();
    $notNull = ternaryFilterQuery($entityManager);
    $null = ternaryFilterQuery($entityManager);

    TernaryFilter::make('isFeatured')->nullable()->apply($notNull, ['value' => 1]);
    TernaryFilter::make('isFeatured')->nullable()->apply($null, ['value' => 0]);

    expect($notNull->getDQL())->toContain('WHERE song.isFeatured IS NOT NULL')
        ->and($null->getDQL())->toContain('WHERE song.isFeatured IS NULL')
        ->and(ternaryFilterTitles($notNull))->toBe(['Alpha', 'Bravo'])
        ->and(ternaryFilterTitles($null))->toBe(['Charlie']);
});

it('qualifies a dotted attribute through the joined alias', function (): void {
    $query = ternaryFilterQuery(ternaryFilterSongs())->leftJoin('song.artist', 'artist');

    TernaryFilter::make('named')->attribute('artist.name')->nullable()->apply($query, ['value' => 0]);

    expect($query->getDQL())->toContain('WHERE artist.name IS NULL');
});

it('indicates the arm label and nothing at all when blank', function (): void {
    $table = Table::make(new FilterStateHost(filterState: [
        'yes' => ['value' => 1],
        'no' => ['value' => 0],
        'unset' => ['value' => null],
    ]));

    $yes = TernaryFilter::make('yes')->label('Featured')->table($table);
    $no = TernaryFilter::make('no')->label('Featured')->falseLabel('Plain')->table($table);
    $unset = TernaryFilter::make('unset')->label('Featured')->table($table);

    expect($yes->getIndicators()[0]->getLabel())->toBe('Featured: Yes')
        ->and($no->getIndicators()[0]->getLabel())->toBe('Featured: Plain')
        ->and($unset->getIndicators())->toBe([])
        ->and($yes->getActiveCount())->toBe(1)
        ->and($no->getActiveCount())->toBe(1)
        ->and($unset->getActiveCount())->toBe(0);
});
