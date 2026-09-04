<?php

declare(strict_types=1);

use Doctrine\ORM\QueryBuilder;
use Sylament\Tables\Columns\Summarizers\Summarizer;
use Sylament\Tables\Columns\TextColumn;
use Sylament\Tables\Contracts\InteractsWithTable;
use Sylament\Tables\Table;
use Sylament\Tables\Tests\Fixtures\Concerns\BareTableHost;
use Sylament\Tables\Tests\Fixtures\Concerns\TableHost;
use Sylament\Tables\Tests\Fixtures\Doctrine\Entity\Song;
use Sylament\Tables\Tests\Fixtures\Doctrine\EntityManagerFactory;

/**
 * A summarizer attached to a column of a `records()` table, so visibility can be
 * asked without any query being involved.
 */
function summarizerVisibility(Summarizer $summarizer, ?InteractsWithTable $host = null): Summarizer
{
    return $summarizer->column(TextColumn::make('plays')->table(Table::make($host ?? new TableHost())));
}

/**
 * Two builders over the same entity that differ only in their constraint, which is
 * exactly what the visibility cache must tell apart.
 *
 * @return array{QueryBuilder, QueryBuilder}
 */
function summarizerVisibilityQueries(): array
{
    $entityManager = EntityManagerFactory::create();
    $build = static fn (): QueryBuilder => $entityManager->createQueryBuilder()->select('song')->from(Song::class, 'song');

    return [$build(), $build()->andWhere('song.plays > 10')];
}

it('is visible by default and hides behind hidden() and visible()', function (): void {
    expect(summarizerVisibility(Summarizer::make())->isVisible())->toBeTrue()
        ->and(summarizerVisibility(Summarizer::make()->hidden())->isHidden())->toBeTrue()
        ->and(summarizerVisibility(Summarizer::make()->visible(false))->isVisible())->toBeFalse()
        ->and(summarizerVisibility(Summarizer::make()->hidden(fn (): bool => true))->isVisible())->toBeFalse()
        ->and(summarizerVisibility(Summarizer::make()->visible(fn (): bool => true))->isVisible())->toBeTrue();
});

it('hides and shows on a host class', function (): void {
    expect(summarizerVisibility(Summarizer::make()->hiddenOn(TableHost::class))->isVisible())->toBeFalse()
        ->and(summarizerVisibility(Summarizer::make()->hiddenOn([BareTableHost::class]))->isVisible())->toBeTrue()
        ->and(summarizerVisibility(Summarizer::make()->visibleOn(TableHost::class))->isVisible())->toBeTrue()
        ->and(summarizerVisibility(Summarizer::make()->visibleOn([BareTableHost::class]))->isVisible())->toBeFalse();
});

/*
 * The documented predicate reads the database (`hidden(fn ($query) => ...)`), and one
 * summarizer is re-pointed at the page query, the all-table query and one query per
 * group in a single render — so the answer is cached per query, never per summarizer.
 * Which window of a dataset is asked about counts as part of that identity: two
 * builders can agree on their DQL and every parameter and still page differently, and
 * a predicate that counts rows answers differently for each.
 */
it('caches its answer per query rather than per summarizer', function (): void {
    [$first, $second] = summarizerVisibilityQueries();
    $evaluations = 0;

    $summarizer = summarizerVisibility(Summarizer::make()->hidden(function () use (&$evaluations): bool {
        $evaluations++;

        return false;
    }));

    $summarizer->query($first)->isVisible();
    $summarizer->query($first)->isVisible();

    expect($evaluations)->toBe(1);

    $summarizer->query($second)->isVisible();

    expect($evaluations)->toBe(2);

    $summarizer->query((clone $first)->setMaxResults(1))->isVisible();

    expect($evaluations)->toBe(3);

    $summarizer->query((clone $first)->setMaxResults(1)->setFirstResult(2))->isVisible();

    expect($evaluations)->toBe(4);
});

it('tells two queries apart by their bound parameters alone', function (): void {
    $entityManager = EntityManagerFactory::create();
    $build = static fn (int $plays): QueryBuilder => $entityManager->createQueryBuilder()
        ->select('song')
        ->from(Song::class, 'song')
        ->andWhere('song.plays > :sylament_plays')
        ->setParameter('sylament_plays', $plays);

    $evaluations = 0;

    $summarizer = summarizerVisibility(Summarizer::make()->hidden(function () use (&$evaluations): bool {
        $evaluations++;

        return false;
    }));

    $summarizer->query($build(10))->isVisible();
    $summarizer->query($build(20))->isVisible();

    expect($evaluations)->toBe(2);
});

it('injects the query into the visibility predicate', function (): void {
    [$first] = summarizerVisibilityQueries();

    $summarizer = summarizerVisibility(Summarizer::make()->hidden(fn (?QueryBuilder $query): bool => $query instanceof QueryBuilder));

    expect($summarizer->query($first)->isVisible())->toBeFalse();
});
