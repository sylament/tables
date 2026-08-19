<?php

declare(strict_types=1);

use Doctrine\ORM\EntityManager;
use Doctrine\ORM\QueryBuilder;
use Sylament\Forms\Components\Select;
use Sylament\Tables\Filters\Indicator;
use Sylament\Tables\Filters\SelectFilter;
use Sylament\Tables\Table;
use Sylament\Tables\Tests\Fixtures\Concerns\FilterStateHost;
use Sylament\Tables\Tests\Fixtures\Doctrine\Entity\Song;
use Sylament\Tables\Tests\Fixtures\Doctrine\EntityManagerFactory;

/*
 * The select filter: the field it builds, the parameter-bound WHERE it composes,
 * the bail chain in front of that WHERE, and the option-label indicators.
 */

function selectFilterSongs(): EntityManager
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

function selectFilterQuery(EntityManager $entityManager): QueryBuilder
{
    return $entityManager->createQueryBuilder()->select('song')->from(Song::class, 'song');
}

/**
 * @return list<string>
 */
function selectFilterTitles(QueryBuilder $query): array
{
    $titles = [];

    foreach ($query->getQuery()->getResult() as $song) {
        $titles[] = $song->getTitle();
    }

    return $titles;
}

it('builds a single-value select carrying the filter configuration', function (): void {
    $field = SelectFilter::make('title')->label('Title')->getFormField();

    expect($field)->toBeInstanceOf(Select::class)
        ->and($field->getName())->toBe('value')
        ->and($field->getLabel())->toBe('Title')
        ->and($field->isMultiple())->toBeFalse()
        ->and($field->getPlaceholder())->toBe('All')
        ->and($field->canSelectPlaceholder())->toBeTrue();
});

it('names the field values when multiple and carries the same placeholder', function (): void {
    $field = SelectFilter::make('title')->multiple()->selectablePlaceholder(false)->getFormField();

    expect($field->getName())->toBe('values')
        ->and($field->isMultiple())->toBeTrue()
        ->and($field->getPlaceholder())->toBe('All')
        ->and($field->canSelectPlaceholder())->toBeFalse();
});

it('reads the options through a closure so a later change reaches the built field', function (): void {
    $filter = SelectFilter::make('title')->options(['Alpha' => 'Alpha']);
    $field = $filter->getFormField();

    $filter->options(['Bravo' => 'Bravo']);

    expect($field->getOptions())->toBe(['Bravo' => 'Bravo']);
});

it('pushes a default onto the field only when it is filled', function (): void {
    expect(SelectFilter::make('a')->getFormField()->getDefaultState())->toBeNull()
        ->and(SelectFilter::make('b')->default(null)->getFormField()->getDefaultState())->toBeNull()
        ->and(SelectFilter::make('c')->default('')->getFormField()->getDefaultState())->toBeNull()
        ->and(SelectFilter::make('d')->default('Alpha')->getFormField()->getDefaultState())->toBe('Alpha');
});

it('binds a single value as an equality parameter', function (): void {
    $query = selectFilterQuery(selectFilterSongs());

    SelectFilter::make('title')->apply($query, ['value' => 'Bravo']);

    expect($query->getDQL())->toContain('WHERE song.title = :sylament_filter_0')
        ->and($query->getDQL())->not->toContain('Bravo')
        ->and($query->getParameter('sylament_filter_0')?->getValue())->toBe('Bravo')
        ->and(selectFilterTitles($query))->toBe(['Bravo']);
});

it('binds multiple values as an IN parameter', function (): void {
    $query = selectFilterQuery(selectFilterSongs());

    SelectFilter::make('title')->multiple()->apply($query, ['values' => ['Alpha', 'Charlie']]);

    expect($query->getDQL())->toContain('WHERE song.title IN(:sylament_filter_0)')
        ->and($query->getParameter('sylament_filter_0')?->getValue())->toBe(['Alpha', 'Charlie'])
        ->and(selectFilterTitles($query))->toBe(['Alpha', 'Charlie']);
});

it('skips a state whose every value is blank and applies a partly filled one', function (): void {
    $entityManager = selectFilterSongs();
    $allBlank = selectFilterQuery($entityManager);
    $mixed = selectFilterQuery($entityManager);
    $missing = selectFilterQuery($entityManager);

    SelectFilter::make('title')->multiple()->apply($allBlank, ['values' => ['', null]]);
    SelectFilter::make('title')->multiple()->apply($mixed, ['values' => ['', 'Bravo']]);
    SelectFilter::make('title')->apply($missing, []);

    expect($allBlank->getDQL())->not->toContain('WHERE')
        ->and($missing->getDQL())->not->toContain('WHERE')
        ->and($mixed->getDQL())->toContain('WHERE song.title IN(:sylament_filter_0)')
        ->and($mixed->getParameter('sylament_filter_0')?->getValue())->toBe(['', 'Bravo'])
        ->and(selectFilterTitles($mixed))->toBe(['Bravo']);
});

it('never touches the query when static, and yields to a user query callback', function (): void {
    $entityManager = selectFilterSongs();
    $untouched = selectFilterQuery($entityManager);
    $overridden = selectFilterQuery($entityManager);

    SelectFilter::make('title')->static()->apply($untouched, ['value' => 'Bravo']);
    SelectFilter::make('title')
        ->query(function (QueryBuilder $query): void {
            $query->andWhere('song.plays > 15');
        })
        ->apply($overridden, ['value' => 'Bravo']);

    expect($untouched->getDQL())->not->toContain('WHERE')
        ->and($overridden->getDQL())->toContain('WHERE song.plays > 15')
        ->and($overridden->getDQL())->not->toContain('song.title')
        ->and(selectFilterTitles($overridden))->toBe(['Bravo', 'Charlie']);
});

it('filters on the attribute when one is set and on the name otherwise', function (): void {
    $entityManager = selectFilterSongs();
    $byName = selectFilterQuery($entityManager);
    $byAttribute = selectFilterQuery($entityManager);

    SelectFilter::make('title')->apply($byName, ['value' => 'Bravo']);
    SelectFilter::make('loudness')->attribute(fn (): string => 'plays')->apply($byAttribute, ['value' => 20]);

    expect(SelectFilter::make('loudness')->getAttribute())->toBe('loudness')
        ->and($byName->getDQL())->toContain('song.title = :sylament_filter_0')
        ->and($byAttribute->getDQL())->toContain('song.plays = :sylament_filter_0')
        ->and(selectFilterTitles($byAttribute))->toBe(['Bravo']);
});

it('resolves a dotted attribute through the joined alias', function (): void {
    $query = selectFilterQuery(selectFilterSongs())->leftJoin('song.artist', 'artist');

    SelectFilter::make('artist')->attribute('artist.name')->apply($query, ['value' => 'Nova']);

    expect($query->getDQL())->toContain('WHERE artist.name = :sylament_filter_0')
        ->and($query->getParameter('sylament_filter_0')?->getValue())->toBe('Nova');
});

it('refuses a dotted attribute whose head is not an alias', function (): void {
    $query = selectFilterQuery(selectFilterSongs());

    SelectFilter::make('publisher')->attribute('publisher.name')->apply($query, ['value' => 'Nova']);
})->throws(LogicException::class, 'Cannot resolve [publisher.name] for this filter: [publisher] is not an alias on the builder, and a filter joins nothing of its own');

it("refuses a mapped association path in its own terms, not the resolver's", function (): void {
    $query = selectFilterQuery(selectFilterSongs());
    $message = null;

    try {
        SelectFilter::make('artist')->attribute('artist.name')->apply($query, ['value' => 'Nova']);
    } catch (LogicException $logicException) {
        $message = $logicException->getMessage();
    }

    expect($message)->toBeString()
        ->and($message)->toContain('[artist] is not an alias on the builder, and a filter joins nothing of its own')
        ->and($message)->not->toContain('association path on');
});

it('resets the key the state actually uses', function (): void {
    expect(SelectFilter::make('title')->getResetState())->toBe(['value' => null])
        ->and(SelectFilter::make('title')->multiple()->getResetState())->toBe(['values' => []])
        ->and(SelectFilter::make('title')->resetState(['value' => 'Alpha'])->getResetState())->toBe(['value' => 'Alpha']);
});

it('joins the selected option labels into one indicator', function (): void {
    $table = Table::make(new FilterStateHost(filterState: [
        'single' => ['value' => 'a'],
        'many' => ['values' => ['a', 'b', 'c']],
        'verbatim' => ['value' => 'a'],
        'unknown' => ['value' => 'z'],
        'empty' => [],
    ]));
    $options = ['a' => 'Alpha', 'b' => 'Bravo', 'c' => 'Charlie'];

    $single = SelectFilter::make('single')->label('Title')->options($options)->table($table);
    $many = SelectFilter::make('many')->label('Title')->multiple()->options($options)->table($table);
    $verbatim = SelectFilter::make('verbatim')->label('Title')->options($options)
        ->indicator(Indicator::make('Pinned'))->table($table);
    $unknown = SelectFilter::make('unknown')->label('Title')->options($options)->table($table);
    $empty = SelectFilter::make('empty')->label('Title')->options($options)->table($table);

    expect($single->getIndicators()[0]->getLabel())->toBe('Title: Alpha')
        ->and($many->getIndicators()[0]->getLabel())->toBe('Title: Alpha, Bravo & Charlie')
        ->and($verbatim->getIndicators()[0]->getLabel())->toBe('Pinned')
        ->and($unknown->getIndicators())->toBe([])
        ->and($empty->getIndicators())->toBe([])
        ->and($single->getActiveCount())->toBe(1)
        ->and($many->getActiveCount())->toBe(1)
        ->and($unknown->getActiveCount())->toBe(1)
        ->and($empty->getActiveCount())->toBe(0);
});

it('reads a grouped option label for the indicator', function (): void {
    $filter = SelectFilter::make('grouped')->label('Title')
        ->options(['Letters' => ['a' => 'Alpha', 'b' => 'Bravo']])
        ->table(Table::make(new FilterStateHost(filterState: ['grouped' => ['value' => 'b']])));

    expect($filter->getIndicators()[0]->getLabel())->toBe('Title: Bravo');
});
