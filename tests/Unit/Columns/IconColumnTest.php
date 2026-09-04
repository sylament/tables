<?php

declare(strict_types=1);

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\QueryBuilder;
use Sylament\Support\Contracts\HasColor as ColorContract;
use Sylament\Support\Contracts\HasIcon as IconContract;
use Sylament\Support\Contracts\HasLabel;
use Sylament\Support\Enums\IconSize;
use Sylament\Support\Icons\IconManager;
use Sylament\Tables\Columns\IconColumn;
use Sylament\Tables\Table;
use Sylament\Tables\Tests\Fixtures\Concerns\TableHost;
use Sylament\Tables\Tests\Fixtures\Doctrine\Entity\Song;
use Sylament\Tables\Tests\Fixtures\Doctrine\EntityManagerFactory;
use Sylament\Tables\Twig\TablesIconAlias;
use Twig\Markup;

enum IconColumnState: string implements ColorContract, HasLabel, IconContract
{
    case Draft = 'draft';

    public function getColor(): string
    {
        return 'warning';
    }

    public function getIcon(): string
    {
        return 'heroicons:pencil';
    }

    public function getLabel(): string
    {
        return 'Drafted';
    }
}

/**
 * The column mounted on a table whose lane is a `query()` over {@see Song}, so the
 * boolean auto-detect has Doctrine metadata to read.
 */
function queryLaneIconColumn(IconColumn $column): IconColumn
{
    $entityManager = EntityManagerFactory::create();

    $table = new TableHost(fn (Table $table): Table => $table
        ->query(fn (): QueryBuilder => $entityManager->createQueryBuilder()->select('song')->from(Song::class, 'song'))
        ->columns([$column]))->getTable();

    $mounted = $table->getColumn($column->getName());

    expect($mounted)->toBeInstanceOf(IconColumn::class);
    assert($mounted instanceof IconColumn);

    return $mounted;
}

it('defaults to the heroicon pair and the success/danger colours', function (): void {
    $column = IconColumn::make('isFeatured');

    expect($column->getTrueIcon())->toBe('heroicons:check-circle')
        ->and($column->getFalseIcon())->toBe('heroicons:x-circle')
        ->and($column->getTrueColor())->toBe('success')
        ->and($column->getFalseColor())->toBe('danger')
        ->and($column->isListWithLineBreaks())->toBeFalse();
});

it('turns the column boolean from every true and false setter', function (IconColumn $column): void {
    expect($column->isBoolean())->toBeTrue();
})->with([
    'true()' => fn (): IconColumn => IconColumn::make('flag')->true(),
    'trueIcon()' => fn (): IconColumn => IconColumn::make('flag')->trueIcon('heroicons:star'),
    'trueColor()' => fn (): IconColumn => IconColumn::make('flag')->trueColor('info'),
    'false()' => fn (): IconColumn => IconColumn::make('flag')->false(),
    'falseIcon()' => fn (): IconColumn => IconColumn::make('flag')->falseIcon('heroicons:star'),
    'falseColor()' => fn (): IconColumn => IconColumn::make('flag')->falseColor('info'),
    'boolean()' => fn (): IconColumn => IconColumn::make('flag')->boolean(),
]);

it('is not boolean on the records lane', function (): void {
    expect(IconColumn::make('flag')->isBoolean())->toBeFalse();
});

/*
 * The two defaults resolve through `IconManager` in PHP rather than through
 * `generateIconHtml()`'s `alias` argument, so a consumer's `sylament.icons` override has to be
 * visible to the getters themselves — to a closure reading them, and to the boolean arm of
 * `getIcon()`. Nothing downstream would notice a `resolveIcon()` that skipped the manager: the
 * shipped glyph renders either way.
 */
it('lets a registered alias override the boolean defaults, on the getters and under getIcon', function (): void {
    $icons = new IconManager();
    $icons->register([
        TablesIconAlias::COLUMNS_ICON_COLUMN_TRUE => 'heroicons:star',
        TablesIconAlias::COLUMNS_ICON_COLUMN_FALSE => 'heroicons:pencil',
    ]);

    $column = IconColumn::make('flag')->boolean();
    $column->setIconManager($icons);

    expect($column->getTrueIcon())->toBe('heroicons:star')
        ->and($column->getFalseIcon())->toBe('heroicons:pencil')
        ->and($column->getIcon(true))->toBe('heroicons:star')
        ->and($column->getIcon(false))->toBe('heroicons:pencil');
});

it('lets an explicit icon beat a registered alias', function (): void {
    $icons = new IconManager();
    $icons->register([TablesIconAlias::COLUMNS_ICON_COLUMN_TRUE => 'heroicons:star']);

    $column = IconColumn::make('flag')->trueIcon('heroicons:check');
    $column->setIconManager($icons);

    expect($column->getTrueIcon())->toBe('heroicons:check');
});

it('answers the boolean icons and colours for a true and a false state', function (): void {
    $column = IconColumn::make('flag')->boolean();

    expect($column->getIcon(true))->toBe('heroicons:check-circle')
        ->and($column->getColor(true))->toBe('success')
        ->and($column->getIcon(false))->toBe('heroicons:x-circle')
        ->and($column->getColor(false))->toBe('danger');
});

it('renders no icon for a null state on a boolean column', function (): void {
    $column = IconColumn::make('flag')->boolean();

    expect($column->getIcon(null))->toBeNull()
        ->and($column->getColor(null))->toBeNull();
});

it('lets a configured icon and colour win over the boolean pair', function (): void {
    $column = IconColumn::make('flag')->boolean()->icon('heroicons:star')->color('info');

    expect($column->getIcon(true))->toBe('heroicons:star')
        ->and($column->getColor(true))->toBe('info');
});

it('falls back to the boolean pair under a state-blind icon closure', function (): void {
    $column = IconColumn::make('flag')->boolean()->icon(fn (mixed $state): ?string => $state === 'special' ? 'heroicons:star' : null);

    expect($column->getIcon('special'))->toBe('heroicons:star')
        ->and($column->getIcon(true))->toBe('heroicons:check-circle');
});

it('suppresses the icon and the colour with false', function (): void {
    $column = IconColumn::make('flag')->boolean()->trueIcon(false);

    expect($column->getTrueIcon())->toBeNull()
        ->and($column->getIcon(true))->toBeNull();
});

it('reads the contracts off an enum state before the boolean arm', function (): void {
    $column = IconColumn::make('status')->boolean();

    expect($column->getIcon(IconColumnState::Draft))->toBe('heroicons:pencil')
        ->and($column->getColor(IconColumnState::Draft))->toBe('warning');
});

it('still refuses true as an icon or a colour', function (): void {
    expect(fn (): mixed => IconColumn::make('flag')->icon(true)->getIcon('x'))
        ->toThrow(LogicException::class, 'must be an icon name')
        ->and(fn (): mixed => IconColumn::make('flag')->color(true)->getColor('x'))
        ->toThrow(LogicException::class, 'must be a palette name');
});

it('resolves the size, dropping base and unset', function (): void {
    expect(IconColumn::make('flag')->getSize('x'))->toBeNull()
        ->and(IconColumn::make('flag')->size('base')->getSize('x'))->toBeNull()
        ->and(IconColumn::make('flag')->size('lg')->getSize('x'))->toBe(IconSize::Large)
        ->and(IconColumn::make('flag')->size(IconSize::Small)->getSize('x'))->toBe(IconSize::Small)
        ->and(IconColumn::make('flag')->size('huge')->getSize('x'))->toBe('huge')
        ->and(IconColumn::make('flag')->size(fn (mixed $state): string => $state === 1 ? 'xl' : 'sm')->getSize(1))->toBe(IconSize::ExtraLarge);
});

it('evaluates the line-break list flag', function (): void {
    expect(IconColumn::make('flag')->listWithLineBreaks()->isListWithLineBreaks())->toBeTrue()
        ->and(IconColumn::make('flag')->listWithLineBreaks(fn (): bool => false)->isListWithLineBreaks())->toBeFalse();
});

it('detects a boolean column from the doctrine metadata', function (): void {
    expect(queryLaneIconColumn(IconColumn::make('isFeatured'))->isBoolean())->toBeTrue()
        ->and(queryLaneIconColumn(IconColumn::make('title'))->isBoolean())->toBeFalse();
});

it('lets an explicit boolean(false) beat the metadata', function (): void {
    expect(queryLaneIconColumn(IconColumn::make('isFeatured')->boolean(false))->isBoolean())->toBeFalse();
});

it('resolves one state item per value, skipping an item whose icon is blank', function (): void {
    $column = IconColumn::make('tags')
        ->state(fn (): array => ['a', 'b'])
        ->icons(['heroicons:star' => 'a']);

    $items = $column->getStateItems();

    expect($items)->toHaveCount(2)
        ->and($items[0]['icon'])->toBe('heroicons:star')
        ->and($items[0]['state'])->toBe('a')
        ->and($items[1]['icon'])->toBeNull()
        ->and($items[1]['alternative'])->toBeNull();
});

it('returns no state items for a blank state', function (): void {
    expect(IconColumn::make('flag')->state(fn (): null => null)->getStateItems())->toBe([]);
});

/*
 * The list case a table actually hits is a loaded to-many association, which arrives as a Doctrine
 * `Collection` rather than an array. `wrap()` would file the collection itself as a single value,
 * so the cell would render one icon resolved from the collection object — which is what the
 * `Traversable` arm exists to prevent, and what nothing else here would notice.
 */
it('walks a traversable state one value at a time', function (): void {
    $column = IconColumn::make('tags')
        ->icons(['heroicons:star' => 'a', 'heroicons:check' => 'b'])
        ->state(fn (): ArrayCollection => new ArrayCollection(['a', 'b']));

    $items = $column->getStateItems();

    expect($items)->toHaveCount(2)
        ->and($items[0]['icon'])->toBe('heroicons:star')
        ->and($items[1]['icon'])->toBe('heroicons:check');
});

it('names each icon, preferring the tooltip and then the boolean copy', function (): void {
    $tooltipped = IconColumn::make('flag')->boolean()->state(fn (): bool => true)->tooltip('Featured article');
    $boolean = IconColumn::make('flag')->boolean()->state(fn (): bool => false);

    expect($tooltipped->getStateItems()[0]['alternative'])->toBe('Featured article')
        ->and($boolean->getStateItems()[0]['alternative'])->toBe('No');
});

/*
 * The boolean copy sits above the label contract in Filament's `match`, and the two arms overlap
 * whenever an explicit `boolean()` meets a `HasLabel` state — the auto-detect off, the enum still
 * carrying a label. Swapping the two arms passes every other case here, so this is what pins the
 * order.
 */
it('puts the boolean copy above the label contract', function (): void {
    $boolean = IconColumn::make('status')->boolean()->state(fn (): IconColumnState => IconColumnState::Draft);
    $plain = IconColumn::make('status')->state(fn (): IconColumnState => IconColumnState::Draft);

    expect($boolean->getStateItems()[0]['alternative'])->toBe('Yes')
        ->and($plain->getStateItems()[0]['alternative'])->toBe('Drafted');
});

it('names an icon from the label, the enum value, markup and a scalar', function (mixed $state, ?string $alternative): void {
    $column = IconColumn::make('status')->icon('heroicons:star')->state(fn (): mixed => $state);

    expect($column->getStateItems()[0]['alternative'])->toBe($alternative);
})->with([
    'label contract' => [IconColumnState::Draft, 'Drafted'],
    'markup' => [new Markup('<b>Bold</b> name', 'UTF-8'), 'Bold name'],
    'scalar' => ['plain', 'plain'],
    'integer' => [7, '7'],
]);

it('carries the per-item url and tooltip on each state item', function (): void {
    $column = IconColumn::make('flag')
        ->icon('heroicons:star')
        ->state(fn (): array => ['a', 'b'])
        ->url(fn (mixed $state): string => 'https://example.com/' . $state)
        ->tooltip(fn (mixed $state): string => 'Tag ' . $state);

    $items = $column->getStateItems();

    expect($items[0]['url'])->toBe('https://example.com/a')
        ->and($items[1]['url'])->toBe('https://example.com/b')
        ->and($items[0]['tooltip'])->toBe('Tag a');
});
