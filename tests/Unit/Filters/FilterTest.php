<?php

declare(strict_types=1);

use Doctrine\ORM\EntityManager;
use Doctrine\ORM\QueryBuilder;
use Sylament\Forms\Components\Checkbox;
use Sylament\Forms\Components\Textarea;
use Sylament\Forms\Components\Toggle;
use Sylament\Tables\Filters\Filter;
use Sylament\Tables\Filters\Indicator;
use Sylament\Tables\Table;
use Sylament\Tables\Tests\Fixtures\Concerns\FilterStateHost;
use Sylament\Tables\Tests\Fixtures\Doctrine\Entity\Song;
use Sylament\Tables\Tests\Fixtures\Doctrine\EntityManagerFactory;

/*
 * The checkbox filter: the one form field it builds, the state that field resets
 * to, the indicator polarity, and the query pass the checkbox gates.
 */

function checkboxFilterSongs(): EntityManager
{
    $entityManager = EntityManagerFactory::create();

    EntityManagerFactory::seed(
        $entityManager,
        new Song(1, 'Alpha', 10),
        new Song(2, 'Bravo', 20),
        new Song(3, 'Charlie', 30),
    );

    return $entityManager;
}

function checkboxFilterQuery(EntityManager $entityManager): QueryBuilder
{
    return $entityManager->createQueryBuilder()->select('song')->from(Song::class, 'song')->orderBy('song.id');
}

/**
 * @return list<string>
 */
function checkboxFilterTitles(QueryBuilder $query): array
{
    $titles = [];

    foreach ($query->getQuery()->getResult() as $song) {
        $titles[] = $song->getTitle();
    }

    return $titles;
}

it('builds a checkbox named isActive carrying the filter label', function (): void {
    $field = Filter::make('is_featured')->label('Featured')->getFormField();

    expect($field)->toBeInstanceOf(Checkbox::class)
        ->and($field->getName())->toBe('isActive')
        ->and($field->getLabel())->toBe('Featured');
});

it('swaps the form component through toggle, checkbox and formComponent', function (): void {
    $filter = Filter::make('is_featured');

    expect($filter->getFormField())->toBeInstanceOf(Checkbox::class)
        ->and($filter->toggle()->getFormField())->toBeInstanceOf(Toggle::class)
        ->and($filter->checkbox()->getFormField())->toBeInstanceOf(Checkbox::class)
        ->and($filter->formComponent(Textarea::class)->getFormField())->toBeInstanceOf(Textarea::class);
});

it('pushes a default onto the field only when it is filled', function (): void {
    expect(Filter::make('a')->getFormField()->getDefaultState())->toBeFalse()
        ->and(Filter::make('b')->default(null)->getFormField()->getDefaultState())->toBeFalse()
        ->and(Filter::make('c')->default('')->getFormField()->getDefaultState())->toBeFalse()
        ->and(Filter::make('d')->default(true)->getFormField()->getDefaultState())->toBeTrue()
        ->and(Filter::make('e')->default('yes')->getFormField()->getDefaultState())->toBe('yes');
});

it('resets a schema-less filter to an unchecked box and defers to resetState with a schema', function (): void {
    $schemaless = Filter::make('a')->resetState(['isActive' => true]);
    $schemad = Filter::make('b')->schema([Checkbox::make('flag')])->resetState(['flag' => true]);

    expect($schemaless->getResetState())->toBe(['isActive' => false])
        ->and($schemad->getResetState())->toBe(['flag' => true])
        ->and(Filter::make('c')->schema([Checkbox::make('flag')])->getResetState())->toBe([]);
});

it('indicates only for a truthy isActive while applying unless isActive is falsy', function (): void {
    $table = Table::make(new FilterStateHost(filterState: [
        'loud' => ['isActive' => true],
        'quiet' => ['isActive' => false],
    ]));
    $narrow = function (QueryBuilder $query): void {
        $query->andWhere('song.plays > 15');
    };

    $active = Filter::make('loud')->label('Loud')->query($narrow)->table($table);
    $inactive = Filter::make('quiet')->label('Quiet')->query($narrow)->table($table);
    $unstated = Filter::make('unstated')->label('Unstated')->query($narrow)->table($table);

    $unstatedQuery = checkboxFilterQuery(checkboxFilterSongs());
    $unstated->apply($unstatedQuery, []);

    expect($active->getIndicators())->toHaveCount(1)
        ->and($inactive->getIndicators())->toBe([])
        ->and($unstated->getIndicators())->toBe([])
        ->and($unstatedQuery->getDQL())->toContain('WHERE song.plays > 15');
});

it('labels the default indicator with the filter label and takes a custom one verbatim', function (): void {
    $table = Table::make(new FilterStateHost(filterState: [
        'loud' => ['isActive' => true],
        'quiet' => ['isActive' => true],
    ]));

    $default = Filter::make('loud')->label('Loud songs')->table($table);
    $custom = Filter::make('quiet')
        ->label('Quiet songs')
        ->indicator(Indicator::make('Hush')->removable(false))
        ->table($table);

    $customIndicators = $custom->getIndicators();

    expect($default->getIndicators()[0]->getLabel())->toBe('Loud songs')
        ->and($default->getActiveCount())->toBe(1)
        ->and($customIndicators[0]->getLabel())->toBe('Hush')
        ->and($customIndicators[0]->isRemovable())->toBeFalse();
});

it('narrows rows for a checked filter and leaves an unchecked one alone', function (): void {
    $entityManager = checkboxFilterSongs();
    $filter = Filter::make('loud')->query(function (QueryBuilder $query): void {
        $query->andWhere('song.plays > 15');
    });

    $checked = checkboxFilterQuery($entityManager);
    $unchecked = checkboxFilterQuery($entityManager);

    $filter->apply($checked, ['isActive' => true]);
    $filter->apply($unchecked, ['isActive' => false]);

    expect(checkboxFilterTitles($checked))->toBe(['Bravo', 'Charlie'])
        ->and(checkboxFilterTitles($unchecked))->toBe(['Alpha', 'Bravo', 'Charlie'])
        ->and($unchecked->getDQL())->not->toContain('WHERE');
});
