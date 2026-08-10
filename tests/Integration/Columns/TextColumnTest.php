<?php

declare(strict_types=1);

namespace Sylament\Tables\Tests\Integration\Columns;

use Sylament\Tables\Columns\TextColumn;
use Sylament\Tables\Table;
use Sylament\Tables\Tests\Fixtures\Concerns\TableHost;
use Sylament\Tables\Tests\Fixtures\Records\Artist;
use Sylament\Tables\Tests\Fixtures\Records\Song;
use Symfony\Component\DomCrawler\Crawler;
use Twig\Markup;

use function Sylament\Tables\Tests\renderToHtml;

/*
 * Drives the shipped `TextColumn` partial through a real table render — the
 * state pipeline (dotted paths, the format hook, a null state, an iterable
 * state) landing inside the cell's own `div.sy.sy-ta-text`, selected through
 * the cell chain so the element carrying the state is the asserted one.
 */

it('renders dotted-path and formatted state into cells', function (): void {
    $host = new TableHost(fn (Table $table): Table => $table
        ->records(fn (): array => [new Song('Airbag', 'OK Computer', new Artist('Radiohead'), id: 1)])
        ->columns([
            TextColumn::make('artist.name'),
            TextColumn::make('title')->formatStateUsing(fn (string $state): string => mb_strtoupper($state)),
        ]));

    $cells = new Crawler(renderToHtml($host->getTable()))
        ->filter('td.sy-ta-cell > div.sy-ta-col > div.sy.sy-ta-text');

    expect($cells->count())->toBe(2)
        ->and($cells->eq(0)->text())->toBe('Radiohead')
        ->and($cells->eq(1)->text())->toBe('AIRBAG');
});

it('renders an empty text element for null state', function (): void {
    $host = new TableHost(fn (Table $table): Table => $table
        ->records(fn (): array => [['note' => null]])
        ->columns([TextColumn::make('note')]));

    $html = renderToHtml($host->getTable());

    expect($html)->toContain('<div class="sy sy-ta-text"></div>');
});

it('joins an iterable state into one comma-separated cell', function (): void {
    $host = new TableHost(fn (Table $table): Table => $table
        ->records(fn (): array => [['tags' => ['symfony', 'forms']]])
        ->columns([TextColumn::make('tags')]));

    $cells = new Crawler(renderToHtml($host->getTable()))
        ->filter('td.sy-ta-cell > div.sy-ta-col > div.sy.sy-ta-text');

    expect($cells->eq(0)->text())->toBe('symfony, forms');
});

it('renders a badge per value with colour classes when badged', function (): void {
    $host = new TableHost(fn (Table $table): Table => $table
        ->records(fn (): array => [['tags' => ['symfony', 'forms']]])
        ->columns([TextColumn::make('tags')->badge()->color('success')]));

    $badges = new Crawler(renderToHtml($host->getTable()))
        ->filter('div.sy-ta-text > span.sy-ta-text-item > span.sy-badge');

    expect($badges->count())->toBe(2)
        ->and($badges->eq(0)->text())->toBe('symfony')
        ->and($badges->eq(1)->text())->toBe('forms')
        ->and($badges->eq(0)->attr('class'))->toContain('sy-color-success');
});

/*
 * The badged branch above skips `ItemComponent` by design, so this is the only render that
 * reaches it — and with it the partial's item FQCN literal, which nothing else parses, and the
 * bundle's public colour-policy glob, which `ColorManager` resolves the class-string through.
 */
it('paints a non-badged item with the item policy classes', function (): void {
    $host = new TableHost(fn (Table $table): Table => $table
        ->records(fn (): array => [['title' => 'Airbag']])
        ->columns([TextColumn::make('title')->color('danger')]));

    $item = new Crawler(renderToHtml($host->getTable()))->filter('div.sy-ta-text > span.sy-ta-text-item');

    expect($item->attr('class'))->toContain('sy-color')
        ->and($item->attr('class'))->toContain('sy-color-danger')
        ->and($item->attr('class'))->toMatch('/\bsy-text-color-\d+\b/');
});

/*
 * The same for the icon policy, whose class-string sits one namespace level deeper — so this is
 * also what proves the deeper glob registers it, a missing registration being a container error
 * at render rather than a quietly uncoloured icon.
 */
it('paints a non-badged icon with the icon policy classes', function (): void {
    $host = new TableHost(fn (Table $table): Table => $table
        ->records(fn (): array => [['title' => 'Airbag']])
        ->columns([TextColumn::make('title')->icon('heroicons:x-mark')->iconColor('danger')]));

    $icon = new Crawler(renderToHtml($host->getTable()))->filter('span.sy-ta-text-item > svg.sy-icon');

    expect($icon->attr('class'))->toContain('sy-color')
        ->and($icon->attr('class'))->toContain('sy-color-danger');
});

it('stamps the column alignment on the cell text element', function (): void {
    $host = new TableHost(fn (Table $table): Table => $table
        ->records(fn (): array => [['title' => 'Airbag']])
        ->columns([TextColumn::make('title')->alignment('center')]));

    expect(new Crawler(renderToHtml($host->getTable()))->filter('div.sy-ta-text')->attr('class'))
        ->toContain('sy-align-center');
});

it('renders descriptions above and below the item', function (): void {
    $host = new TableHost(fn (Table $table): Table => $table
        ->records(fn (): array => [['title' => 'Airbag']])
        ->columns([
            TextColumn::make('title')
                ->description('OK Computer', position: 'above')
                ->description('1997'),
        ]));

    $cell = new Crawler(renderToHtml($host->getTable()))->filter('div.sy-ta-text');
    $children = $cell->children();

    expect($cell->attr('class'))->toContain('sy-ta-text-has-descriptions')
        ->and($children->eq(0)->text())->toBe('OK Computer')
        ->and($children->eq(1)->text())->toBe('Airbag')
        ->and($children->eq(2)->text())->toBe('1997');
});

it('renders size, weight, font family, wrap and line clamp', function (): void {
    $host = new TableHost(fn (Table $table): Table => $table
        ->records(fn (): array => [['title' => 'Airbag']])
        ->columns([
            TextColumn::make('title')
                ->size('lg')
                ->weight('bold')
                ->fontFamily('mono')
                ->wrap()
                ->lineClamp(2),
        ]));

    $html = renderToHtml($host->getTable());
    $item = new Crawler($html)->filter('div.sy-ta-text > span.sy-ta-text-item');

    expect(new Crawler($html)->filter('div.sy-ta-text')->attr('class'))->toContain('sy-wrapped')
        ->and($item->attr('class'))->toContain('sy-size-lg')
        ->and($item->attr('class'))->toContain('sy-font-bold')
        ->and($item->attr('class'))->toContain('sy-font-mono')
        ->and($item->attr('style'))->toContain('--sy-line-clamp: 2');
});

/*
 * Ordering, not presence: the content is a text node, so `children()` holds the one
 * `<svg>` either way and an assertion over it passes for both positions. Reading the
 * item's inner HTML is what distinguishes them — deleting either branch of the
 * template's position check fails exactly one of the two expectations below.
 */
it('renders an icon before or after the content', function (): void {
    $itemHtml = function (string $position): string {
        $host = new TableHost(fn (Table $table): Table => $table
            ->records(fn (): array => [['title' => 'Airbag']])
            ->columns([TextColumn::make('title')->icon('heroicons:x-mark')->iconPosition($position)]));

        return new Crawler(renderToHtml($host->getTable()))
            ->filter('div.sy-ta-text > span.sy-ta-text-item')
            ->html();
    };

    expect($itemHtml('before'))->toStartWith('<svg')
        ->and($itemHtml('before'))->toEndWith('Airbag')
        ->and($itemHtml('after'))->toStartWith('Airbag')
        ->and($itemHtml('after'))->toEndWith('</svg>');
});

/*
 * The icon's spacing hook, which nothing else emits: Filament separates icon from text with the
 * collapsed whitespace its PHP-echo template happens to print, and this port's partial strips all
 * of it, so without a class the two render flush against each other.
 */
it('stamps the icon with the position class its margin rule reads', function (): void {
    $iconClasses = function (string $position): string {
        $host = new TableHost(fn (Table $table): Table => $table
            ->records(fn (): array => [['title' => 'Airbag']])
            ->columns([TextColumn::make('title')->icon('heroicons:x-mark')->iconPosition($position)]));

        return (string) new Crawler(renderToHtml($host->getTable()))
            ->filter('span.sy-ta-text-item > svg.sy-icon')
            ->attr('class');
    };

    expect($iconClasses('before'))->toContain('sy-ta-text-icon-before')
        ->not->toContain('sy-ta-text-icon-after')
        ->and($iconClasses('after'))->toContain('sy-ta-text-icon-after')
        ->not->toContain('sy-ta-text-icon-before');
});

it('renders a badged icon inside the badge, not beside it', function (): void {
    $host = new TableHost(fn (Table $table): Table => $table
        ->records(fn (): array => [['title' => 'Airbag']])
        ->columns([TextColumn::make('title')->badge()->icon('heroicons:x-mark')]));

    $item = new Crawler(renderToHtml($host->getTable()))->filter('div.sy-ta-text > span.sy-ta-text-item');

    expect($item->children()->count())->toBe(1)
        ->and($item->children()->first()->nodeName())->toBe('span')
        ->and($item->filter('span.sy-badge > svg')->count())->toBe(1)
        ->and($item->filter('svg')->count())->toBe(1);
});

it('drops a blank description and the layout class it would flip', function (): void {
    $host = new TableHost(fn (Table $table): Table => $table
        ->records(fn (): array => [['title' => 'Airbag']])
        ->columns([
            TextColumn::make('title')
                ->description('   ', position: 'above')
                ->description(''),
        ]));

    $cell = new Crawler(renderToHtml($host->getTable()))->filter('div.sy-ta-text');

    expect($cell->attr('class'))->not->toContain('sy-ta-text-has-descriptions')
        ->and($cell->filter('p.sy-ta-text-description')->count())->toBe(0);
});

/*
 * The same drop for `Markup`, and it is a different mechanism rather than the same one again:
 * `Markup` is `Countable`, so Laravel's count-first ordering would call a whitespace-only one
 * filled — support's `filled()` special-cases it to text semantics, and the Twig `filled` test
 * this template asks is that helper.
 */
it('drops a whitespace-only markup description', function (): void {
    $host = new TableHost(fn (Table $table): Table => $table
        ->records(fn (): array => [['title' => 'Airbag']])
        ->columns([TextColumn::make('title')->description(new Markup('   ', 'UTF-8'))]));

    $cell = new Crawler(renderToHtml($host->getTable()))->filter('div.sy-ta-text');

    expect($cell->attr('class'))->not->toContain('sy-ta-text-has-descriptions')
        ->and($cell->filter('p.sy-ta-text-description')->count())->toBe(0);
});
