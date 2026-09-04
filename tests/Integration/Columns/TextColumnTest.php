<?php

declare(strict_types=1);

namespace Sylament\Tables\Tests\Integration\Columns;

use Closure;
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
        ->filter('div.sy-ta-text > ul > li.sy-ta-text-item > span.sy-badge');

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

/**
 * One column's cell, rendered through a real table. The column is built *inside*, after the
 * kernel has booted, because `make()` only reaches the container's non-shared definition — and
 * with it the `#[Required]` setters, the HTML sanitizer among them — while a static instance is
 * registered, and each test starts with none.
 *
 * @param Closure(): TextColumn $makeColumn
 */
function textCell(Closure $makeColumn, mixed $state, string $name = 'value'): Crawler
{
    test()->getContainer();

    $host = new TableHost(fn (Table $table): Table => $table
        ->records(fn (): array => [[$name => $state]])
        ->columns([$makeColumn()]));

    return new Crawler(renderToHtml($host->getTable()))
        ->filter('td.sy-ta-cell > div.sy-ta-col > div.sy.sy-ta-text');
}

it('renders the placeholder and the empty tooltip for a blank state', function (): void {
    $cell = textCell(fn (): TextColumn => TextColumn::make('note')->placeholder('None')->emptyTooltip('Nothing recorded'), null, 'note');

    expect($cell->filter('p.sy-ta-placeholder')->text())->toBe('None')
        ->and($cell->attr('title'))->toBe('Nothing recorded');
});

it('stamps the extra attributes and the inline class on the root', function (): void {
    $cell = textCell(fn (): TextColumn => TextColumn::make('title')->inline()->extraAttributes(['data-probe' => 'yes']), 'Airbag', 'title');

    expect($cell->attr('class'))->toContain('sy-inline')
        ->and($cell->attr('data-probe'))->toBe('yes');
});

it('links each item through the column url', function (): void {
    $cell = textCell(
        fn (): TextColumn => TextColumn::make('tags')->bulleted()->url(fn (string $state): string => '/tags/' . $state)->openUrlInNewTab(),
        ['symfony', 'forms'],
        'tags',
    );
    $links = $cell->filter('ul > li.sy-ta-text-item > a');

    expect($links->count())->toBe(2)
        ->and($links->eq(0)->attr('href'))->toBe('/tags/symfony')
        ->and($links->eq(0)->attr('target'))->toBe('_blank')
        ->and($links->eq(1)->attr('href'))->toBe('/tags/forms');
});

it('renders a per-item tooltip as a title on the content element', function (): void {
    $cell = textCell(fn (): TextColumn => TextColumn::make('title')->tooltip(fn (string $state): string => 'Full: ' . $state), 'Airbag', 'title');
    $content = $cell->filter('span.sy-ta-text-item > span');

    expect($content->attr('title'))->toBe('Full: Airbag')
        ->and($content->text())->toBe('Airbag');
});

/*
 * The copy control is a `span` carrying `role="button"`, never a `<button>` and never a served
 * `tabindex` — the cell may render inside a record `<a>`, where either is invalid markup. The
 * controller stamps the `tabindex` at connect.
 */
it('renders the copyable control with the clipboard controller and its three values', function (): void {
    $cell = textCell(
        fn (): TextColumn => TextColumn::make('title')->copyable()->copyableState(fn (string $state): string => mb_strtoupper($state))->copyMessage('Got it')->copyMessageDuration(500),
        'Airbag',
        'title',
    );
    $control = $cell->filter('span.sy-ta-text-item > span.sy-copyable');

    expect($control->attr('role'))->toBe('button')
        ->and($control->attr('tabindex'))->toBeNull()
        ->and($control->attr('data-controller'))->toBe('sylament--support--clipboard')
        ->and($control->attr('data-sylament--support--clipboard-text-value'))->toBe('AIRBAG')
        ->and($control->attr('data-sylament--support--clipboard-message-value'))->toBe('Got it')
        ->and($control->attr('data-sylament--support--clipboard-duration-value'))->toBe('500')
        ->and($control->attr('data-action'))->toContain('click->sylament--support--clipboard#copy')
        ->and($control->attr('data-action'))->toContain('keydown.enter->sylament--support--clipboard#copy')
        ->and($control->attr('data-action'))->toContain('keydown.space->sylament--support--clipboard#copy');
});

it('falls back to the formatted state as the copied text', function (): void {
    $cell = textCell(fn (): TextColumn => TextColumn::make('title')->copyable()->prefix('# '), 'Airbag', 'title');

    expect($cell->filter('span.sy-copyable')->attr('data-sylament--support--clipboard-text-value'))->toBe('# Airbag');
});

it('puts the copy handler on the badge pill rather than beside it', function (): void {
    $cell = textCell(fn (): TextColumn => TextColumn::make('title')->badge()->copyable(), 'Airbag', 'title');
    $badge = $cell->filter('span.sy-ta-text-item > span.sy-badge');

    expect($badge->attr('class'))->toContain('sy-copyable')
        ->and($badge->attr('role'))->toBe('button')
        ->and($badge->attr('data-controller'))->toContain('sylament--support--clipboard')
        ->and($cell->filter('span.sy-ta-text-item > span.sy-copyable > span.sy-badge')->count())->toBe(0);
});

it('renders a bulleted list of one value and marks the root', function (): void {
    $cell = textCell(fn (): TextColumn => TextColumn::make('tags')->bulleted(), ['symfony'], 'tags');

    expect($cell->attr('class'))->toContain('sy-bulleted')
        ->and($cell->attr('class'))->toContain('sy-ta-text-has-line-breaks')
        ->and($cell->filter('ul > li.sy-ta-text-item')->count())->toBe(1);
});

it('keeps a line-broken list as list items rather than joining them', function (): void {
    $cell = textCell(fn (): TextColumn => TextColumn::make('tags')->listWithLineBreaks(), ['symfony', 'forms'], 'tags');
    $items = $cell->filter('ul > li.sy-ta-text-item');

    expect($cell->attr('class'))->toContain('sy-ta-text-has-line-breaks')
        ->and($cell->attr('class'))->not->toContain('sy-bulleted')
        ->and($items->count())->toBe(2)
        ->and($items->eq(1)->text())->toBe('forms');
});

it('drops the values past the limit and counts them in a static message', function (): void {
    $cell = textCell(fn (): TextColumn => TextColumn::make('tags')->listWithLineBreaks()->limitList(2), ['a', 'b', 'c', 'd'], 'tags');

    expect($cell->attr('class'))->toContain('sy-ta-text-list-limited')
        ->and($cell->filter('ul > li')->count())->toBe(2)
        ->and($cell->filter('div.sy-ta-text-list-limited-message')->text())->toBe('and 2 more')
        ->and($cell->filter('div.sy-ta-text-list-limited-message span')->count())->toBe(0);
});

/*
 * The only shape that renders the over-limit values at all: the client toggles them, so the
 * server has to ship them hidden. Dropping the `listWithLineBreaks()` half of the slice gate
 * would leave the toggles behind with nothing to reveal, which the next case pins.
 */
it('keeps its own controller over one a consumer put in the extra attributes', function (): void {
    $cell = textCell(
        fn (): TextColumn => TextColumn::make('tags')
            ->listWithLineBreaks()
            ->limitList(2)
            ->expandableLimitedList()
            ->extraAttributes(['data-controller' => 'app--probe']),
        ['a', 'b', 'c', 'd'],
        'tags',
    );

    expect($cell->attr('data-controller'))->toBe('sylament--tables--limited-list');
});

it('ships the over-limit values hidden behind two toggles when the list is expandable', function (): void {
    $cell = textCell(
        fn (): TextColumn => TextColumn::make('tags')->listWithLineBreaks()->limitList(2)->expandableLimitedList(),
        ['a', 'b', 'c', 'd'],
        'tags',
    );
    $items = $cell->filter('ul > li');
    $toggles = $cell->filter('div.sy-ta-text-list-limited-message > span');

    expect($cell->attr('data-controller'))->toBe('sylament--tables--limited-list')
        ->and($cell->attr('data-sylament--tables--limited-list-expanded-value'))->toBe('false')
        ->and($items->count())->toBe(4)
        ->and($items->eq(1)->attr('hidden'))->toBeNull()
        ->and($items->eq(2)->attr('data-over-limit'))->not->toBeNull()
        ->and($items->eq(2)->attr('hidden'))->not->toBeNull()
        ->and($toggles->count())->toBe(2)
        ->and($toggles->eq(0)->text())->toBe('Show 2 more')
        ->and($toggles->eq(0)->attr('role'))->toBe('button')
        ->and($toggles->eq(0)->attr('tabindex'))->toBeNull()
        ->and($toggles->eq(0)->attr('data-action'))->toContain('click->sylament--tables--limited-list#expand')
        ->and($toggles->eq(0)->attr('data-action'))->toContain('keydown.enter->sylament--tables--limited-list#expand')
        ->and($toggles->eq(0)->attr('data-action'))->toContain('keydown.space->sylament--tables--limited-list#expand')
        ->and($toggles->eq(1)->text())->toBe('Show 2 less')
        ->and($toggles->eq(1)->attr('hidden'))->not->toBeNull()
        ->and($toggles->eq(1)->attr('data-action'))->toContain('click->sylament--tables--limited-list#collapse')
        ->and($toggles->eq(1)->attr('data-action'))->toContain('keydown.enter->sylament--tables--limited-list#collapse')
        ->and($toggles->eq(1)->attr('data-action'))->toContain('keydown.space->sylament--tables--limited-list#collapse');
});

it('keeps the static message when an expandable list has no line breaks to reveal', function (): void {
    $cell = textCell(fn (): TextColumn => TextColumn::make('tags')->limitList(2)->expandableLimitedList(), ['a', 'b', 'c', 'd'], 'tags');

    expect($cell->attr('data-controller'))->toBeNull()
        ->and($cell->text())->toContain('a, b')
        ->and($cell->filter('div.sy-ta-text-list-limited-message')->text())->toBe('and 2 more');
});

it('formats a date and a money state inside the cell', function (): void {
    expect(textCell(fn (): TextColumn => TextColumn::make('publishedAt')->date(), '2026-09-02 13:45:00', 'publishedAt')->text())->toBe('Sep 2, 2026')
        ->and(textCell(fn (): TextColumn => TextColumn::make('price')->money(locale: 'en_US'), 1234.5, 'price')->text())->toBe('$1,234.50');
});

/*
 * The sanitizer arrives through the container's `#[Required]` setter, so this is also what proves
 * a column built by `make()` inside a render carries one.
 */
it('renders sanitized html raw rather than escaping it', function (): void {
    $cell = textCell(fn (): TextColumn => TextColumn::make('body')->html(), '<p>Hello <script>alert(1)</script>world</p>', 'body');

    expect($cell->filter('span.sy-ta-text-item > p')->count())->toBe(1)
        ->and($cell->filter('script')->count())->toBe(0)
        ->and($cell->html())->toContain('<p>Hello world</p>');
});

it('numbers the rows of a paginated page from the page offset', function (): void {
    $host = new TableHost(fn (Table $table): Table => $table
        ->records(fn (): array => [['title' => 'Airbag'], ['title' => 'Karma Police']])
        ->columns([TextColumn::make('index')->rowIndex(), TextColumn::make('title')])
        ->paginated([2]));

    $host->tableRecordsPerPage = '2';
    $host->tablePage = 3;

    $cells = new Crawler(renderToHtml($host->getTable()))->filter('td.sy-ta-cell-index div.sy-ta-text');

    expect($cells->count())->toBe(2)
        ->and($cells->eq(0)->text())->toBe('5')
        ->and($cells->eq(1)->text())->toBe('6');
});
