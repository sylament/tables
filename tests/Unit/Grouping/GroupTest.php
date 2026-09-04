<?php

declare(strict_types=1);

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\QueryBuilder;
use Sylament\Tables\Grouping\Group;
use Sylament\Tables\Table;
use Sylament\Tables\Tests\Fixtures\Concerns\TableHost;
use Sylament\Tables\Tests\Fixtures\Doctrine\Entity\Artist as ArtistEntity;
use Sylament\Tables\Tests\Fixtures\Doctrine\Entity\Song as SongEntity;
use Sylament\Tables\Tests\Fixtures\Doctrine\EntityManagerFactory;
use Sylament\Tables\Tests\Fixtures\Records\Artist;
use Sylament\Tables\Tests\Fixtures\Records\Song;
use Sylament\Tables\Tests\Fixtures\Records\Status;
use Twig\Markup;

/*
 * The group value object on its own: the key, title and description chains it
 * reads off a record, and the three query arms it applies to a builder. The
 * host's resolution of *which* group is in force stays in
 * `Unit/Concerns/CanGroupRecordsTest.php`.
 */

/**
 * A pure enum, which has no backing value to key a group by — declared here
 * rather than under `Fixtures/`, which the test kernel autoregisters.
 */
enum PureGroupKey
{
    case Only;
}

function groupQueryBuilder(?EntityManager $entityManager = null): QueryBuilder
{
    return ($entityManager ?? EntityManagerFactory::create())
        ->createQueryBuilder()
        ->select('song')
        ->from(SongEntity::class, 'song');
}

/**
 * A group mounted on a real table, which is what the date title's format and
 * every closure's `table` injection read through.
 */
function groupOnTable(Group $group): Group
{
    return $group->table(new TableHost()->getTable());
}

it('defaults the column to the id', function (): void {
    expect(Group::make('status')->getColumn())->toBe('status')
        ->and(Group::make('status')->getId())->toBe('status');
});

it('takes an explicit column over the id', function (): void {
    expect(Group::make('by-artist')->column('artist.name')->getColumn())->toBe('artist.name');
});

it('refuses a group with no id', function (): void {
    expect(fn (): Group => Group::make())->toThrow(LogicException::class, 'must have a unique id');
});

/*
 * The grouping property writes `<id>:<direction>` as one token and splits it on the
 * first colon, so an id carrying one would resolve to whatever sits before it — a
 * group the table does not know, which reads back as no grouping at all.
 */
it('refuses a group whose id carries a colon', function (): void {
    expect(fn (): Group => Group::make('artist:name'))->toThrow(LogicException::class, 'must not contain a colon')
        ->and(fn (): Group => Group::make('status')->id('a:b'))->toThrow(LogicException::class, 'must not contain a colon');
});

it('derives a label from the id, kebabbed and spaced and title-cased', function (string $id, string $label): void {
    expect(Group::make($id)->getLabel())->toBe($label);
})->with([
    'a plain id' => ['status', 'Status'],
    'a camel-cased id' => ['publishedAt', 'Published at'],
    'a snake-cased id' => ['published_at', 'Published at'],
    // The chain is `beforeLast('.')->afterLast('.')`, so a dotted path labels
    // itself after the relation it crosses rather than the field it ends on.
    'a relation path' => ['artist.name', 'Artist'],
    'a nested relation path' => ['artist.label.name', 'Label'],
]);

it('evaluates a closure label through the invoker', function (): void {
    expect(Group::make('status')->label(fn (Group $group): string => 'Group: ' . $group->getId())->getLabel())
        ->toBe('Group: status');
});

it('renders a Markup label raw', function (): void {
    $label = Group::make('status')->label(new Markup('<b>Status</b>', 'UTF-8'))->getLabel();

    expect($label)->toBeInstanceOf(Markup::class)
        ->and((string) $label)->toBe('<b>Status</b>');
});

it('reads the key off a record property', function (): void {
    expect(Group::make('album')->getKey(new Song('Airbag', 'OK Computer')))->toBe('OK Computer');
});

it('reads the key across a dot path', function (): void {
    $song = new Song('Airbag', 'OK Computer', new Artist('Radiohead'));

    expect(Group::make('artist.name')->getStringKey($song))->toBe('Radiohead');
});

it('unwraps a backed enum key to its value', function (): void {
    $song = new Song('Airbag', 'OK Computer', status: Status::Published);

    expect(Group::make('status')->getStringKey($song))->toBe('published');
});

it('reads null for a blank column rather than the empty string', function (): void {
    $song = new Song('Airbag', 'OK Computer');

    expect(Group::make('artist.name')->getStringKey($song))->toBeNull()
        ->and(Group::make('status')->getStringKey($song))->toBeNull();
});

it('keys nothing for a value that cannot identify a group', function (mixed $value): void {
    $group = Group::make('status')->getKeyFromRecordUsing(fn (): mixed => $value);

    expect($group->getStringKey(['id' => '1']))->toBeNull();
})->with([
    'an array' => [['a', 'b']],
    'a collection' => [fn (): ArrayCollection => new ArrayCollection(['a', 'b'])],
    'an object that is not stringable' => [fn (): stdClass => new stdClass()],
    'a non-backed enum' => [PureGroupKey::Only],
]);

it('takes a getKeyFromRecordUsing closure over the column', function (): void {
    $group = Group::make('status')->getKeyFromRecordUsing(
        fn (Song $record, string $column): string => $column . ':' . $record->album,
    );

    expect($group->getStringKey(new Song('Airbag', 'OK Computer')))->toBe('status:OK Computer');
});

it('collapses a date key to its day, whatever the instant', function (): void {
    $group = Group::make('releasedAt')->date();
    $song = new SongEntity(1, 'Airbag', 100);
    $song->setReleasedAt(new DateTimeImmutable('2026-09-02 14:03:11'));

    $charted = new SongEntity(2, 'Exit Music', 200);
    $charted->setChartedOn(new DateTimeImmutable('2026-09-02'));

    expect($group->getStringKey($song))->toBe('2026-09-02')
        ->and(Group::make('chartedOn')->date()->getStringKey($charted))->toBe('2026-09-02');
});

it('keys nothing for a value that is not a date under date()', function (): void {
    expect(Group::make('album')->date()->getStringKey(new Song('Airbag', 'OK Computer')))->toBeNull();
});

it('keys and titles a datetime column by its string form outside date()', function (): void {
    $song = new SongEntity(1, 'Airbag', 100);
    $song->setReleasedAt(new DateTimeImmutable('2026-09-02 14:03:11'));

    $later = new SongEntity(2, 'Exit Music', 200);
    $later->setReleasedAt(new DateTimeImmutable('2026-09-02 19:41:00'));

    $group = Group::make('releasedAt');

    // Two instants on one day are two groups without `date()`, as upstream:
    // Eloquent's Carbon prints itself and a `DateTimeImmutable` does not, so
    // without the format arm both records would read as one blank group.
    expect($group->getStringKey($song))->toBe('2026-09-02 14:03:11')
        ->and($group->getStringKey($later))->toBe('2026-09-02 19:41:00')
        ->and($group->getTitle($song))->toBe('2026-09-02 14:03:11')
        ->and($group->getTitle($later))->toBe('2026-09-02 19:41:00');
});

it('keys an object record the same way it keys an array', function (): void {
    $group = Group::make('status');

    expect($group->getStringKey(new Song('Airbag', 'OK Computer', status: Status::Draft)))
        ->toBe('draft')
        ->and($group->getStringKey(['status' => Status::Draft]))->toBe('draft');
});

it('titles a group with its raw column value', function (): void {
    expect(Group::make('album')->getTitle(new Song('Airbag', 'OK Computer')))->toBe('OK Computer');
});

it('titles a group through HasLabel when the value is one', function (): void {
    $song = new Song('Airbag', 'OK Computer', status: Status::Published);

    expect(Group::make('status')->getTitle($song))->toBe('Published');
});

it('formats a date title with the table default date display format', function (): void {
    $song = new SongEntity(1, 'Airbag', 100);
    $song->setReleasedAt(new DateTimeImmutable('2026-09-02 14:03:11'));

    expect(groupOnTable(Group::make('releasedAt')->date())->getTitle($song))->toBe('Sep 2, 2026');
});

it('takes a getTitleFromRecordUsing closure', function (): void {
    $group = Group::make('album')->getTitleFromRecordUsing(
        fn (Song $record, string $column): string => mb_strtoupper($column) . ' ' . $record->album,
    );

    expect($group->getTitle(new Song('Airbag', 'OK Computer')))->toBe('ALBUM OK Computer');
});

it('memoises a title per key and re-resolves under a title closure', function (): void {
    $memoised = 0;
    $resolved = 0;

    $cached = Group::make('album')->getKeyFromRecordUsing(function (Song $record) use (&$memoised): string {
        $memoised++;

        return $record->album;
    });

    $closured = Group::make('album')->getTitleFromRecordUsing(function (Song $record) use (&$resolved): string {
        $resolved++;

        return $record->album;
    });

    $song = new Song('Airbag', 'OK Computer');

    $cached->getTitle($song, 'OK Computer');
    $cached->getTitle($song, 'OK Computer');

    $closured->getTitle($song);
    $closured->getTitle($song);

    expect($memoised)->toBe(0)
        ->and($resolved)->toBe(2);
});

it('returns a Markup title untouched, before the date branch', function (): void {
    $markup = new Markup('<b>Airbag</b>', 'UTF-8');
    $group = Group::make('title')->date()->getTitleFromRecordUsing(fn (): Markup => $markup);

    expect($group->getTitle(new Song('Airbag', 'OK Computer')))->toBe($markup);
});

it('describes a group only when a description closure is set', function (): void {
    $song = new Song('Airbag', 'OK Computer');

    $described = Group::make('album')->getDescriptionFromRecordUsing(
        fn (Song $record, ?string $title): string => $title . ' by ' . $record->title,
    );

    expect(Group::make('album')->getDescription($song, 'OK Computer'))->toBeNull()
        ->and($described->getDescription($song, 'OK Computer'))->toBe('OK Computer by Airbag');
});

it('is not collapsible by default and flips with collapsible()', function (): void {
    expect(Group::make('status')->isCollapsible())->toBeFalse()
        ->and(Group::make('status')->collapsible()->isCollapsible())->toBeTrue()
        ->and(Group::make('status')->collapsible(false)->isCollapsible())->toBeFalse();
});

it('prefixes a title with its label by default and drops it under titlePrefixedWithLabel(false)', function (): void {
    expect(Group::make('status')->isTitlePrefixedWithLabel())->toBeTrue()
        ->and(Group::make('status')->titlePrefixedWithLabel(false)->isTitlePrefixedWithLabel())->toBeFalse();
});

it('is not a date group by default and flips with date()', function (): void {
    expect(Group::make('releasedAt')->isDate())->toBeFalse()
        ->and(Group::make('releasedAt')->date()->isDate())->toBeTrue();
});

it('orders by the grouped column in the asked direction', function (): void {
    $query = groupQueryBuilder();

    Group::make('plays')->orderQuery($query, 'desc');

    expect($query->getDQL())->toContain('ORDER BY song.plays desc');
});

it('orders across a relation by joining it', function (): void {
    $query = groupQueryBuilder();

    Group::make('artist.name')->orderQuery($query, 'asc');

    expect($query->getDQL())->toContain('LEFT JOIN song.artist sy_artist', 'ORDER BY sy_artist.name asc');
});

it('adds its ordering rather than replacing one already on the builder', function (): void {
    $query = groupQueryBuilder()->addOrderBy('song.id', 'ASC');

    Group::make('plays')->orderQuery($query, 'desc');

    expect($query->getDQL())->toContain('ORDER BY song.id ASC, song.plays desc');
});

it('takes an orderQueryUsing closure with column, direction and query injections', function (): void {
    $query = groupQueryBuilder();

    $group = Group::make('plays')->orderQueryUsing(
        function (QueryBuilder $query, string $column, string $direction): void {
            $query->addOrderBy('song.title', $direction === 'desc' ? 'desc' : 'asc');
            $query->addOrderBy('song.' . $column, 'asc');
        },
    );

    expect($group->orderQuery($query, 'desc'))->toBe($query)
        ->and($query->getDQL())->toContain('ORDER BY song.title desc, song.plays asc');
});

it('takes the builder an orderQueryUsing closure returns', function (): void {
    $query = groupQueryBuilder();
    $ordered = groupQueryBuilder()->addOrderBy('song.title', 'desc');

    $group = Group::make('plays')->orderQueryUsing(fn (): QueryBuilder => $ordered);

    expect($group->orderQuery($query, 'asc'))->toBe($ordered);
});

it('hands back the builder when an orderQueryUsing closure returns null', function (): void {
    $query = groupQueryBuilder();

    $group = Group::make('plays')->orderQueryUsing(fn (): null => null);

    expect($group->orderQuery($query, 'asc'))->toBe($query)
        ->and($query->getDQL())->not->toContain('ORDER BY');
});

it('needs no DATE() to order a date group', function (): void {
    $query = groupQueryBuilder();

    Group::make('releasedAt')->date()->orderQuery($query, 'asc');

    expect($query->getDQL())->toContain('ORDER BY song.releasedAt asc')
        ->and($query->getDQL())->not->toContain('DATE(');
});

it('scopes a query to one key with andWhere, not where', function (): void {
    $query = groupQueryBuilder()->andWhere('song.plays > 15');

    Group::make('title')->scopeQueryByKey($query, 'Airbag');

    expect($query->getDQL())->toContain('WHERE song.plays > 15 AND song.title = :sylament_group_0');
});

it('binds the key as a parameter rather than interpolating it', function (): void {
    $query = groupQueryBuilder();

    Group::make('title')->scopeQueryByKey($query, 'Airbag');

    expect($query->getDQL())->toContain(':sylament_group_0')
        ->and($query->getDQL())->not->toContain('Airbag')
        ->and($query->getParameter('sylament_group_0')?->getValue())->toBe('Airbag');
});

it('counter-suffixes its parameter past the ones already bound', function (): void {
    $query = groupQueryBuilder()->andWhere('song.plays > :plays')->setParameter('plays', 15);

    Group::make('title')->scopeQueryByKey($query, 'Airbag');

    expect($query->getDQL())->toContain('song.title = :sylament_group_1')
        ->and($query->getParameter('plays')?->getValue())->toBe(15);
});

it('scopes a null key to IS NULL rather than = NULL, and with andWhere', function (): void {
    // Primed, because `where()` writes the identical DQL on a bare builder: what
    // the conjunction protects is the filters and search the caller already added.
    $query = groupQueryBuilder()->andWhere('song.plays > 15');

    Group::make('mood')->scopeQueryByKey($query, null);

    expect($query->getDQL())->toContain('WHERE song.plays > 15 AND song.mood IS NULL')
        ->and($query->getParameters())->toHaveCount(0);
});

it('scopes across a relation', function (): void {
    $entityManager = EntityManagerFactory::create();
    $radiohead = new ArtistEntity(1, 'Radiohead');

    EntityManagerFactory::seed(
        $entityManager,
        $radiohead,
        new SongEntity(1, 'Airbag', 100, $radiohead),
        new SongEntity(2, 'Solo', 200),
    );

    $query = groupQueryBuilder($entityManager);

    Group::make('artist.name')->scopeQueryByKey($query, 'Radiohead');

    expect($query->getDQL())->toContain('LEFT JOIN song.artist sy_artist', 'sy_artist.name = :sylament_group_0')
        ->and(array_map(
            static fn (SongEntity $song): string => $song->getTitle(),
            $query->getQuery()->getResult(),
        ))->toBe(['Airbag']);
});

it('includes records with no related row when the key is null', function (): void {
    $entityManager = EntityManagerFactory::create();
    $radiohead = new ArtistEntity(1, 'Radiohead');

    EntityManagerFactory::seed(
        $entityManager,
        $radiohead,
        new SongEntity(1, 'Airbag', 100, $radiohead),
        new SongEntity(2, 'Solo', 200),
    );

    $query = groupQueryBuilder($entityManager);

    Group::make('artist.name')->scopeQueryByKey($query, null);

    expect(array_map(
        static fn (SongEntity $song): string => $song->getTitle(),
        $query->getQuery()->getResult(),
    ))->toBe(['Solo']);
});

it('takes the builder a scopeQueryByKeyUsing closure returns', function (): void {
    $query = groupQueryBuilder();
    $narrowed = groupQueryBuilder()->andWhere('song.title = :taken')->setParameter('taken', 'Airbag');

    $group = Group::make('title')->scopeQueryByKeyUsing(fn (): QueryBuilder => $narrowed);

    expect($group->scopeQueryByKey($query, 'Airbag'))->toBe($narrowed)
        ->and($query->getDQL())->not->toContain('WHERE');
});

it('takes a scopeQueryByKeyUsing closure with column, key and query injections', function (): void {
    $query = groupQueryBuilder();

    $group = Group::make('title')->scopeQueryByKeyUsing(
        function (QueryBuilder $query, string $column, ?string $key): void {
            $query->andWhere(sprintf('song.%s = :taken', $column))->setParameter('taken', $key);
        },
    );

    expect($group->scopeQueryByKey($query, 'Airbag'))->toBe($query)
        ->and($query->getDQL())->toContain('song.title = :taken')
        ->and($query->getDQL())->not->toContain('sylament_group_');
});

it('matches a date group on the day, not the instant', function (): void {
    $entityManager = EntityManagerFactory::create();
    $inside = new SongEntity(1, 'Airbag', 100);
    $inside->setReleasedAt(new DateTimeImmutable('2026-09-02 14:03:11'));

    $edge = new SongEntity(2, 'Solo', 200);
    $edge->setReleasedAt(new DateTimeImmutable('2026-09-03 00:00:00'));

    EntityManagerFactory::seed($entityManager, $inside, $edge);

    // Primed for the same reason the null arm is: the range has to be `andWhere`,
    // or a filtered table summarizes and selects the rows its filters excluded.
    $query = groupQueryBuilder($entityManager)->andWhere('song.plays > 15');

    Group::make('releasedAt')->date()->scopeQueryByKey($query, '2026-09-02');

    expect($query->getDQL())->toContain('WHERE song.plays > 15 AND (song.releasedAt >= :sylament_group_0 AND song.releasedAt < :sylament_group_1)')
        ->and($query->getDQL())->not->toContain('DATE(')
        ->and(array_map(
            static fn (SongEntity $song): string => $song->getTitle(),
            $query->getQuery()->getResult(),
        ))->toBe(['Airbag']);
});

it('binds the range of a date column with the column own DBAL type', function (): void {
    $entityManager = EntityManagerFactory::create();
    $onTheDay = new SongEntity(1, 'Airbag', 100);
    $onTheDay->setChartedOn(new DateTimeImmutable('2026-09-02'));

    $theDayAfter = new SongEntity(2, 'Solo', 200);
    $theDayAfter->setChartedOn(new DateTimeImmutable('2026-09-03'));

    EntityManagerFactory::seed($entityManager, $onTheDay, $theDayAfter);

    $query = groupQueryBuilder($entityManager);

    Group::make('chartedOn')->date()->scopeQueryByKey($query, '2026-09-02');

    // An untyped datetime literal compares as text against a `date` column and
    // answers the neighbouring day too, so the bound type is the assertion.
    expect($query->getParameter('sylament_group_0')?->getType())->toBe('date_immutable')
        ->and($query->getParameter('sylament_group_1')?->getType())->toBe('date_immutable')
        ->and(array_map(
            static fn (SongEntity $song): string => $song->getTitle(),
            $query->getQuery()->getResult(),
        ))->toBe(['Airbag']);
});

it('scopes an unparsable date key to nothing at all', function (): void {
    $entityManager = EntityManagerFactory::create();

    EntityManagerFactory::seed($entityManager, new SongEntity(1, 'Airbag', 100));

    $query = groupQueryBuilder($entityManager);

    Group::make('releasedAt')->date()->scopeQueryByKey($query, 'not-a-day');

    expect($query->getDQL())->toContain('WHERE 1 = 0')
        ->and($query->getQuery()->getResult())->toBe([]);
});

it('delegates scopeQuery to scopeQueryByKey through the record string key', function (): void {
    $query = groupQueryBuilder();

    $returned = Group::make('title')->scopeQuery($query, ['title' => 'Airbag']);

    expect($returned)->toBe($query)
        ->and($query->getDQL())->toContain('song.title = :sylament_group_0')
        ->and($query->getParameter('sylament_group_0')?->getValue())->toBe('Airbag');
});

it('takes the builder a scopeQueryUsing closure returns', function (): void {
    $query = groupQueryBuilder();
    $narrowed = groupQueryBuilder()->andWhere('song.title = :taken')->setParameter('taken', 'Airbag');

    $group = Group::make('title')->scopeQueryUsing(fn (): QueryBuilder => $narrowed);

    expect($group->scopeQuery($query, new Song('Airbag', 'OK Computer')))->toBe($narrowed)
        ->and($query->getDQL())->not->toContain('WHERE');
});

it('takes a scopeQueryUsing closure over the delegation', function (): void {
    $query = groupQueryBuilder();

    $group = Group::make('title')->scopeQueryUsing(
        function (QueryBuilder $query, string $column, Song $record): void {
            $query->andWhere(sprintf('song.%s = :taken', $column))->setParameter('taken', $record->title);
        },
    );

    expect($group->scopeQuery($query, new Song('Airbag', 'OK Computer')))->toBe($query)
        ->and($query->getDQL())->toContain('song.title = :taken')
        ->and($query->getDQL())->not->toContain('sylament_group_');
});

it('fetch-joins the grouped relation once, however often it is asked', function (): void {
    $query = groupQueryBuilder();
    $group = Group::make('artist.name');

    $group->applyEagerLoading($query);
    $group->applyEagerLoading($query);

    $dql = $query->getDQL();

    expect($dql)->toContain('LEFT JOIN song.artist sy_artist')
        ->and(mb_substr_count($dql, 'LEFT JOIN song.artist'))->toBe(1)
        ->and(mb_substr_count($dql, 'sy_artist,'))->toBe(0)
        ->and($dql)->toContain('SELECT song, sy_artist');
});

it('fetch-joins nothing for a column that crosses no relation', function (): void {
    $query = groupQueryBuilder();

    Group::make('plays')->applyEagerLoading($query);

    expect($query->getDQL())->not->toContain('LEFT JOIN');
});

it('is not mounted to a table until one is given', function (): void {
    expect(fn (): Table => Group::make('status')->getTable())
        ->toThrow(LogicException::class, 'is not mounted to a table');
});
