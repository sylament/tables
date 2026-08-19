<?php

declare(strict_types=1);

namespace Sylament\Tables\Tests\Integration;

use Closure;
use Sylament\Support\Pagination\Paginator;
use Sylament\Tables\Columns\TextColumn;
use Sylament\Tables\Contracts\InteractsWithTable;
use Sylament\Tables\Enums\FiltersLayout;
use Sylament\Tables\Enums\FiltersResetActionPosition;
use Sylament\Tables\Filters\BaseFilter;
use Sylament\Tables\Filters\Filter;
use Sylament\Tables\Filters\Indicator;
use Sylament\Tables\Filters\SelectFilter;
use Sylament\Tables\Table;
use Sylament\Tables\Tests\Fixtures\Concerns\SchemaTableHost;
use Sylament\Tables\Tests\Fixtures\Concerns\TableHost;
use Sylament\Tables\Tests\Fixtures\Twig\Components\BrowsableSongsTable;
use Sylament\Tables\Tests\Fixtures\Twig\Components\ExtremeLinksSongsTable;
use Sylament\Tables\Twig\Extension\TableExtension;
use Symfony\Component\DomCrawler\Crawler;
use Twig\Environment;
use Twig\Error\RuntimeError;

/*
 * The browsing chrome of the shipped `Table` partial — sortable headers, the
 * search toolbar, the pagination footer, the filters trigger and its panel, and
 * the active-filter indicator row — rendered through the booted kernel's Twig
 * against hosts that turn them on, and asserted with the Crawler so a renamed
 * class or a flattened wrapper fails here rather than silently downstream in the
 * stylesheet or the demo's browser pass.
 */

function browsingTwig(): Environment
{
    $twig = test()->getContainer()->get('twig');

    assert($twig instanceof Environment);

    return $twig;
}

function renderTableHost(InteractsWithTable $component): string
{
    $twig = browsingTwig();

    return (string) $twig->getExtension(TableExtension::class)->renderTable($twig, ['this' => $component]);
}

function browsableTableHtml(int $page = 1, ?string $sort = null): string
{
    if ($page === 1 && $sort === null) {
        return browsingTwig()->render('@TablesFixtures/browsable_table_page.html.twig');
    }

    $component = new BrowsableSongsTable();
    $component->tablePage = $page;
    $component->tableSort = $sort;

    return renderTableHost($component);
}

function extremeLinksTableHtml(int $page = 1): string
{
    $component = new ExtremeLinksSongsTable();
    $component->tablePage = $page;

    return renderTableHost($component);
}

function songsTableHtml(): string
{
    return browsingTwig()->render('@TablesFixtures/table_extension_page.html.twig');
}

/**
 * @param list<array<string, string>> $records
 */
function simplePaginatedTableHtml(int $page, array $records, bool $hasMoreRecords = false): string
{
    $component = new TableHost(fn (Table $table): Table => $table
        ->records(function (int $page, int|string|null $recordsPerPage) use ($records, $hasMoreRecords): Paginator {
            assert(is_int($recordsPerPage));

            return new Paginator(
                records: $records,
                totalRecordCount: null,
                page: $page,
                recordsPerPage: $recordsPerPage,
                hasMoreRecords: $hasMoreRecords,
            );
        })
        ->columns([TextColumn::make('title')])
        ->paginated([2, 5]));

    $component->tablePage = $page;

    return renderTableHost($component);
}

it('renders a sortable header as an aria-annotated live action button', function (): void {
    $crawler = new Crawler(browsableTableHtml());

    $sortableHeader = $crawler->filter('th.sy-ta-header-cell')->first();
    $button = $sortableHeader->filter('button.sy-ta-header-cell-sort-btn');

    expect($sortableHeader->attr('scope'))->toBe('col')
        ->and($sortableHeader->attr('aria-sort'))->toBe('none')
        ->and($button->count())->toBe(1)
        ->and($button->attr('data-action'))->toContain('live#action')
        ->and($button->attr('data-live-action-param'))->toBe('sortTable')
        ->and($button->attr('data-live-column-param'))->toBe('title');
});

it('annotates the actively sorted header with its direction and sorted class', function (): void {
    $crawler = new Crawler(browsableTableHtml(sort: 'title:asc'));

    $sortedHeader = $crawler->filter('th.sy-ta-header-cell')->first();

    expect($sortedHeader->attr('aria-sort'))->toBe('ascending')
        ->and($sortedHeader->attr('class'))->toContain('sy-ta-header-cell-sorted')
        ->and($sortedHeader->filter('button.sy-ta-header-cell-sort-btn svg.sy-icon')->count())->toBe(1)
        ->and($sortedHeader->filter('button.sy-ta-header-cell-sort-btn svg.sy-icon')->outerHtml())->toContain('0 0 20 20')
        ->and($sortedHeader->filter('button.sy-ta-header-cell-sort-btn svg.sy-icon')->attr('class'))->toContain('sy-size-md');
});

it('renders an unsortable header without a button', function (): void {
    $crawler = new Crawler(browsableTableHtml());

    expect($crawler->filter('th.sy-ta-header-cell')->eq(1)->filter('button')->count())->toBe(0)
        ->and($crawler->filter('th.sy-ta-header-cell')->eq(1)->attr('aria-sort'))->toBeNull();
});

it('renders the search field bound to tableSearch with the debounce', function (): void {
    $crawler = new Crawler(browsableTableHtml());
    $input = $crawler->filter('.sy-ta-header-toolbar .sy-ta-search-field input.sy-ta-search-input');

    expect($input->count())->toBe(1)
        ->and($input->attr('data-model'))->toBe('debounce(500)|tableSearch')
        ->and($input->attr('type'))->toBe('search')
        ->and($input->attr('placeholder'))->toBe('Search')
        ->and($crawler->filter('.sy-ta-search-field > svg.sy-icon')->attr('class'))->toContain('sy-size-md');
});

it('renders the pagination footer with overview, gapped window, and per-page select', function (): void {
    $crawler = new Crawler(browsableTableHtml());

    expect($crawler->filter('nav.sy-pagination')->count())->toBe(1)
        ->and($crawler->filter('.sy-pagination-overview')->text())->toContain('Showing 1 to 5 of 43 results');

    $items = $crawler->filter('.sy-pagination-items button.sy-pagination-item');
    expect($items->count())->toBeGreaterThanOrEqual(6)
        ->and($crawler->filter('.sy-pagination-items .sy-pagination-gap')->count())->toBe(1);

    $active = $crawler->filter('.sy-pagination-item-active');
    expect($active->attr('aria-current'))->toBe('page')
        ->and(mb_trim($active->text()))->toBe('1');

    $pageTwo = $items->reduce(fn (Crawler $node): bool => mb_trim($node->text()) === '2')->first();
    expect($pageTwo->attr('data-live-action-param'))->toBe('gotoTablePage')
        ->and($pageTwo->attr('data-live-page-param'))->toBe('2');

    $select = $crawler->filter('select.sy-pagination-per-page-select');
    expect($select->attr('data-model'))->toBe('tableRecordsPerPage')
        ->and($select->filter('option[selected]')->attr('value'))->toBe('5')
        ->and($select->filter('option')->last()->text())->toContain('All');
});

it('adds the first and last page links when extreme links are on', function (): void {
    $crawler = new Crawler(extremeLinksTableHtml(page: 2));

    $first = $crawler->filter('.sy-pagination-items button[rel="first"]');
    $last = $crawler->filter('.sy-pagination-items button[rel="last"]');

    expect($first->count())->toBe(1)
        ->and($first->attr('data-live-page-param'))->toBe('1')
        ->and($first->filter('svg.sy-icon')->count())->toBe(1)
        ->and($last->count())->toBe(1)
        ->and($last->attr('data-live-page-param'))->toBe('9')
        ->and($last->filter('svg.sy-icon')->count())->toBe(1)
        ->and(new Crawler(browsableTableHtml(page: 2))->filter('.sy-pagination-items button[rel="first"]')->count())->toBe(0);
});

it('renders the footer chevrons at the default icon size', function (): void {
    $chevrons = new Crawler(extremeLinksTableHtml(page: 2))
        ->filter('.sy-pagination-items button[rel] svg.sy-icon');

    expect($chevrons->each(fn (Crawler $icon): ?string => $icon->closest('button')?->attr('rel')))
        ->toBe(['first', 'prev', 'next', 'last']);

    foreach ($chevrons->each(fn (Crawler $icon): array => explode(' ', (string) $icon->attr('class'))) as $classes) {
        expect($classes)->toContain('sy-size-md')
            ->and($classes)->not->toContain('sy-size-sm');
    }
});

it('renders no footer and no toolbar for a plain unpaginated, unsearchable table', function (): void {
    $html = songsTableHtml();

    expect($html)->not->toContain('sy-pagination')
        ->and($html)->not->toContain('sy-ta-header-toolbar');
});

it('keeps the footer beside the empty state on an overflow page', function (): void {
    $crawler = new Crawler(browsableTableHtml(page: 99));

    expect($crawler->filter('.sy-ta-empty-state')->count())->toBe(1)
        ->and($crawler->filter('nav.sy-pagination')->count())->toBe(1);
});

it('renders the simple footer without a window when the paginator has no total', function (): void {
    $crawler = new Crawler(simplePaginatedTableHtml(page: 2, records: [
        ['__key' => '3', 'title' => 'Song 03'],
        ['__key' => '4', 'title' => 'Song 04'],
    ], hasMoreRecords: true));

    $overview = $crawler->filter('.sy-pagination-overview')->text();

    expect($crawler->filter('nav.sy-pagination')->count())->toBe(1)
        ->and($overview)->toContain('Showing 3 to 4 results')
        ->and($overview)->not->toContain(' of ');

    $items = $crawler->filter('ol.sy-pagination-items button');

    expect($crawler->filter('.sy-pagination-gap')->count())->toBe(0)
        ->and($items->each(fn (Crawler $item): ?string => $item->attr('rel')))->toBe(['prev', 'next']);

    $previous = $crawler->filter('ol.sy-pagination-items button[rel="prev"]');
    $next = $crawler->filter('ol.sy-pagination-items button[rel="next"]');

    expect($previous->attr('data-live-action-param'))->toBe('gotoTablePage')
        ->and($previous->attr('data-live-page-param'))->toBe('1')
        ->and($next->attr('data-live-action-param'))->toBe('gotoTablePage')
        ->and($next->attr('data-live-page-param'))->toBe('3');

    $select = $crawler->filter('select.sy-pagination-per-page-select');

    expect($select->count())->toBe(1)
        ->and($select->attr('data-model'))->toBe('tableRecordsPerPage');
});

it('suppresses the footer for an empty simple first page', function (): void {
    $html = simplePaginatedTableHtml(page: 1, records: []);

    expect($html)->not->toContain('sy-pagination')
        ->and(new Crawler($html)->filter('.sy-ta-empty-state')->count())->toBe(1);
});

it('keeps the simple footer on an empty page past the data', function (): void {
    $crawler = new Crawler(simplePaginatedTableHtml(page: 3, records: []));

    $overview = $crawler->filter('.sy-pagination-overview')->text();

    expect($crawler->filter('.sy-ta-empty-state')->count())->toBe(1)
        ->and($crawler->filter('nav.sy-pagination')->count())->toBe(1)
        ->and($overview)->toContain('Showing 0 results')
        ->and($overview)->not->toContain(' of ')
        ->and($crawler->filter('ol.sy-pagination-items button')->each(fn (Crawler $item): ?string => $item->attr('rel')))->toBe(['prev']);
});

/**
 * @return list<BaseFilter>
 */
function songFilters(): array
{
    return [
        SelectFilter::make('plays')->options(['100' => 'Hundred', '200' => 'Two hundred']),
        Filter::make('popular'),
    ];
}

/**
 * @param array<string, mixed> $tableFilters
 */
function filtersTableHtml(Closure $configureTable, array $tableFilters = []): string
{
    $component = new SchemaTableHost($configureTable);
    $component->tableFilters = $tableFilters;

    return renderTableHost($component);
}

function filteredSongsTable(Closure $configureTable): Closure
{
    return fn (Table $table): Table => $configureTable(
        $table
            ->records(fn (): array => [['__key' => '1', 'title' => 'Airbag']])
            ->columns([TextColumn::make('title')]),
    );
}

it('renders the filters trigger with a badge counting the active indicators', function (): void {
    $crawler = new Crawler(filtersTableHtml(
        filteredSongsTable(fn (Table $table): Table => $table->filters(songFilters())),
        ['plays' => ['value' => '200'], 'popular' => ['isActive' => true]],
    ));

    $trigger = $crawler->filter('.sy-ta-header-toolbar .sy-ta-header-toolbar-end .sy-ta-filters-trigger');
    $badge = $trigger->filter('.sy-ta-filters-trigger-badge-ctn > .sy-ta-filters-trigger-badge');

    expect($trigger->count())->toBe(1)
        ->and($trigger->filter('button.sy-icon-btn')->attr('aria-label'))->toBe('Filter')
        ->and($trigger->filter('button.sy-icon-btn svg.sy-icon')->count())->toBe(1)
        ->and(mb_trim($badge->text()))->toBe('2')
        ->and($badge->attr('class'))->toContain('sy-size-xs');
});

/*
 * The panel is a laid-out descendant of `.sy-ta-ctn`, whose `overflow-x-auto`
 * clips it on a short or narrow table. Filament never hits this — its scroll
 * container is `fi-ta-content-ctn`, a sibling *below* the toolbar — so the fix is
 * the remedy Filament does apply where a dropdown is inside the clip
 * (`HasRecordActions` stamps `defaultDropdownTeleport(true)` on record groups):
 * the fixed positioning strategy, which no `overflow` ancestor can clip.
 */
it('teleports the filters panel so a short table cannot clip it', function (): void {
    $crawler = new Crawler(filtersTableHtml(
        filteredSongsTable(fn (Table $table): Table => $table->filters(songFilters())),
    ));

    expect($crawler->filter('.sy-ta-filters-dropdown')->attr('data-sylament--support--dropdown-teleport-value'))->toBe('true');
});

it('puts the search field and the filters trigger in one end group', function (): void {
    $crawler = new Crawler(filtersTableHtml(
        filteredSongsTable(fn (Table $table): Table => $table->filters(songFilters())->searchable()),
    ));

    $toolbar = $crawler->filter('.sy-ta-header-toolbar');
    $end = $toolbar->filter('.sy-ta-header-toolbar-end');

    expect($toolbar->children()->count())->toBe(1)
        ->and($end->filter('.sy-ta-search-field')->count())->toBe(1)
        ->and($end->filter('.sy-ta-filters-trigger')->count())->toBe(1);
});

it('renders no filters trigger and no toolbar when the table declares none', function (): void {
    $html = filtersTableHtml(filteredSongsTable(fn (Table $table): Table => $table));

    expect($html)->not->toContain('sy-ta-filters-trigger')
        ->and($html)->not->toContain('sy-ta-header-toolbar');
});

it('renders neither trigger nor panel for the hidden filters layout, and keeps the indicators', function (): void {
    $html = filtersTableHtml(
        filteredSongsTable(fn (Table $table): Table => $table->filters(songFilters(), FiltersLayout::Hidden)),
        ['plays' => ['value' => '200'], 'popular' => ['isActive' => true]],
    );

    $chips = new Crawler($html)->filter('.sy-ta-filter-indicators .sy-ta-filter-indicator');

    expect($html)->not->toContain('sy-ta-filters-trigger')
        ->and($html)->not->toContain('sy-ta-filters-dropdown')
        ->and($html)->not->toContain('sy-ta-header-toolbar')
        ->and($chips->count())->toBe(2)
        ->and(mb_trim($chips->eq(0)->filter('.sy-badge-label')->text()))->toBe('Plays: Two hundred');
});

it('renders the panel fields and the apply button while filters are deferred', function (): void {
    $crawler = new Crawler(filtersTableHtml(
        filteredSongsTable(fn (Table $table): Table => $table->filters(songFilters())),
    ));

    $panel = $crawler->filter('.sy-ta-filters-dropdown .sy-dropdown-panel .sy-ta-filters');

    expect($panel->count())->toBe(1)
        ->and(mb_trim($panel->filter('h3.sy-ta-filters-heading')->text()))->toBe('Filters')
        ->and($panel->filter('select.sy-fo-select')->count())->toBe(1)
        ->and($panel->filter('input.sy-fo-checkbox')->count())->toBe(1);

    $apply = $panel->filter('.sy-ta-filters-actions-ctn button.sy-btn');

    expect($apply->count())->toBe(1)
        ->and($apply->attr('data-action'))->toContain('live#action')
        ->and($apply->attr('data-live-action-param'))->toBe('applyTableFilters');
});

it('renders no footer at all when filters are live and the reset sits in the header', function (): void {
    $crawler = new Crawler(filtersTableHtml(
        filteredSongsTable(fn (Table $table): Table => $table->filters(songFilters())->deferFilters(false)),
    ));

    $panel = $crawler->filter('.sy-ta-filters');
    $reset = $panel->filter('.sy-ta-filters-header button.sy-link');

    expect($panel->filter('.sy-ta-filters-actions-ctn')->count())->toBe(0)
        ->and($reset->count())->toBe(1)
        ->and($reset->attr('data-live-action-param'))->toBe('resetTableFiltersForm');
});

it('moves the reset control into the footer for the footer position', function (): void {
    $crawler = new Crawler(filtersTableHtml(
        filteredSongsTable(fn (Table $table): Table => $table
            ->filters(songFilters())
            ->filtersResetActionPosition(FiltersResetActionPosition::Footer)),
    ));

    $panel = $crawler->filter('.sy-ta-filters');
    $footerButtons = $panel->filter('.sy-ta-filters-actions-ctn button.sy-btn');

    expect($panel->filter('.sy-ta-filters-header button.sy-link')->count())->toBe(0)
        ->and($footerButtons->count())->toBe(2)
        ->and($footerButtons->eq(1)->attr('data-live-action-param'))->toBe('resetTableFiltersForm');
});

/*
 * The refusal is a `LogicException` from `hasFiltersDropdown()`, and Twig wraps
 * anything a template throws in a `RuntimeError` carrying the original message
 * (`Template::yield()`), so that is what a consumer sees — the same shape
 * actions' `ModalsRenderTest` pins for its host-resolution throw.
 */
it('refuses an unshipped filters layout on render', function (): void {
    expect(static fn (): string => filtersTableHtml(
        filteredSongsTable(fn (Table $table): Table => $table->filters(songFilters(), FiltersLayout::AboveContent)),
    ))->toThrow(RuntimeError::class, '[AboveContent] filters layout');
});

it('renders one removable chip per indicator under the active-filters label', function (): void {
    $crawler = new Crawler(filtersTableHtml(
        filteredSongsTable(fn (Table $table): Table => $table->filters(songFilters())),
        ['plays' => ['value' => '200'], 'popular' => ['isActive' => true]],
    ));

    $row = $crawler->filter('.sy-ta-filter-indicators');
    $chips = $row->filter('.sy-ta-filter-indicator');
    $unkeyedRemove = $chips->eq(1)->filter('button.sy-badge-delete-btn');

    expect($row->count())->toBe(1)
        ->and(mb_trim($row->filter('.sy-ta-filter-indicators-label')->text()))->toBe('Active filters')
        ->and($chips->count())->toBe(2)
        ->and($chips->eq(0)->attr('class'))->toContain('sy-badge')
        ->and($row->filter('.sy-ta-filter-indicator .sy-badge')->count())->toBe(0)
        ->and(mb_trim($chips->eq(0)->filter('.sy-badge-label')->text()))->toBe('Plays: Two hundred')
        ->and(mb_trim($chips->eq(1)->filter('.sy-badge-label')->text()))->toBe('Popular')
        ->and($unkeyedRemove->attr('data-live-filter-name-param'))->toBe('popular')
        ->and($unkeyedRemove->attr('data-live-field-param'))->toBeNull();
});

it('carries the filter name and the remove field on the chip delete button', function (): void {
    $crawler = new Crawler(filtersTableHtml(
        filteredSongsTable(fn (Table $table): Table => $table->filters([
            Filter::make('playsRange')->indicateUsing(fn (): array => ['from' => Indicator::make('From: 150')]),
        ])),
        ['playsRange' => ['from' => '150']],
    ));

    $remove = $crawler->filter('.sy-ta-filter-indicator button.sy-badge-delete-btn');

    expect($remove->count())->toBe(1)
        ->and($remove->attr('aria-label'))->toBe('Remove filter')
        ->and($remove->attr('data-action'))->toContain('live#action')
        ->and($remove->attr('data-live-action-param'))->toBe('removeTableFilter')
        ->and($remove->attr('data-live-filter-name-param'))->toBe('playsRange')
        ->and($remove->attr('data-live-field-param'))->toBe('from');
});

it('renders the global search chip wired to resetTableSearch', function (): void {
    $component = new SchemaTableHost(filteredSongsTable(fn (Table $table): Table => $table
        ->columns([TextColumn::make('title')->searchable()])
        ->filters(songFilters())));
    $component->tableSearch = 'Airbag';

    $crawler = new Crawler(renderTableHost($component));
    $chip = $crawler->filter('.sy-ta-filter-indicator')->first();
    $remove = $chip->filter('button.sy-badge-delete-btn');

    expect(mb_trim($chip->filter('.sy-badge-label')->text()))->toBe('Search: Airbag')
        ->and($remove->attr('data-live-action-param'))->toBe('resetTableSearch')
        ->and($remove->attr('data-live-filter-name-param'))->toBeNull()
        ->and($remove->attr('data-live-field-param'))->toBeNull();
});

it('renders no delete button and no remove-all for a chip that is not removable', function (): void {
    $crawler = new Crawler(filtersTableHtml(
        filteredSongsTable(fn (Table $table): Table => $table->filters([
            Filter::make('archived')->indicateUsing(fn (): array => [Indicator::make('Archived')->removable(false)]),
        ])),
        ['archived' => ['isActive' => true]],
    ));

    expect($crawler->filter('.sy-ta-filter-indicator')->count())->toBe(1)
        ->and($crawler->filter('.sy-badge-delete-btn')->count())->toBe(0)
        ->and($crawler->filter('.sy-ta-filter-indicators-remove-all-btn')->count())->toBe(0);
});

it('renders the remove-all button as soon as one chip is removable', function (): void {
    $crawler = new Crawler(filtersTableHtml(
        filteredSongsTable(fn (Table $table): Table => $table->filters([
            Filter::make('archived')->indicateUsing(fn (): array => [Indicator::make('Archived')->removable(false)]),
            Filter::make('popular'),
        ])),
        ['archived' => ['isActive' => true], 'popular' => ['isActive' => true]],
    ));

    $removeAll = $crawler->filter('.sy-ta-filter-indicators button.sy-ta-filter-indicators-remove-all-btn');

    expect($crawler->filter('.sy-ta-filter-indicator')->count())->toBe(2)
        ->and($removeAll->count())->toBe(1)
        ->and($removeAll->attr('aria-label'))->toBe('Remove all filters')
        ->and($removeAll->attr('data-live-action-param'))->toBe('removeTableFilters');
});
