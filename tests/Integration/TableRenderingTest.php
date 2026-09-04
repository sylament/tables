<?php

declare(strict_types=1);

namespace Sylament\Tables\Tests\Integration;

use Closure;
use stdClass;
use Sylament\Actions\Action;
use Sylament\Actions\ActionGroup;
use Sylament\Actions\BulkAction;
use Sylament\Actions\BulkActionGroup;
use Sylament\Support\Pagination\Paginator;
use Sylament\Tables\Columns\TextColumn;
use Sylament\Tables\Contracts\InteractsWithTable;
use Sylament\Tables\Enums\ColumnManagerLayout;
use Sylament\Tables\Enums\ColumnManagerResetActionPosition;
use Sylament\Tables\Enums\FiltersLayout;
use Sylament\Tables\Enums\FiltersResetActionPosition;
use Sylament\Tables\Enums\RecordActionsPosition;
use Sylament\Tables\Enums\RecordCheckboxPosition;
use Sylament\Tables\Filters\BaseFilter;
use Sylament\Tables\Filters\Filter;
use Sylament\Tables\Filters\Indicator;
use Sylament\Tables\Filters\SelectFilter;
use Sylament\Tables\Table;
use Sylament\Tables\Tests\Fixtures\Concerns\SchemaTableHost;
use Sylament\Tables\Tests\Fixtures\Concerns\TableHost;
use Sylament\Tables\Tests\Fixtures\Records\Song;
use Sylament\Tables\Tests\Fixtures\Twig\Components\BrowsableSongsTable;
use Sylament\Tables\Tests\Fixtures\Twig\Components\ExtremeLinksSongsTable;
use Sylament\Tables\Twig\Extension\TableExtension;
use Symfony\Component\DomCrawler\Crawler;
use Twig\Environment;
use Twig\Error\RuntimeError;
use Twig\Markup;

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

it('keeps the toolbar field on the extracted component, unbound to a value', function (): void {
    $crawler = new Crawler(browsableTableHtml());
    $field = $crawler->filter('.sy-ta-header-toolbar-end > label.sy.sy-ta-search-field');
    $input = $field->filter('input.sy-ta-search-input');

    expect($field->count())->toBe(1)
        ->and($input->attr('maxlength'))->toBe('1000')
        ->and($input->attr('autocomplete'))->toBe('off')
        ->and($input->attr('aria-label'))->toBe('Search')
        ->and($input->attr('value'))->toBeNull();
});

it('binds the toolbar field on change rather than on a debounce for a table that asks', function (): void {
    $crawler = new Crawler(renderTableHost(new TableHost(static fn (Table $table): Table => $table
        ->records(fn (): array => [new Song('Airbag', 'OK Computer', id: 1)])
        ->columns([TextColumn::make('title')->searchable()])
        ->searchOnBlur()
        ->searchPlaceholder('Find a song'))));

    $input = $crawler->filter('.sy-ta-header-toolbar-end input.sy-ta-search-input');

    expect($input->attr('data-model'))->toBe('on(change)|tableSearch')
        ->and($input->attr('placeholder'))->toBe('Find a song');
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
        ->and($html)->not->toContain('sy-ta-filters-above-content-ctn')
        ->and($html)->not->toContain('sy-ta-filters-below-content-ctn')
        ->and($html)->not->toContain('sy-modal')
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

it('takes the panel footer from the apply action a customization made visible', function (): void {
    $crawler = new Crawler(filtersTableHtml(
        filteredSongsTable(fn (Table $table): Table => $table
            ->filters(songFilters())
            ->deferFilters(false)
            ->filtersApplyAction(static fn (Action $action): Action => $action->visible()->label('Refilter'))),
    ));

    $apply = $crawler->filter('.sy-ta-filters .sy-ta-filters-actions-ctn button.sy-btn');

    expect($apply->count())->toBe(1)
        ->and(mb_trim($apply->text()))->toBe('Refilter')
        ->and($apply->attr('data-live-action-param'))->toBe('applyTableFilters');
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
 * The refusal is a `LogicException` from the layout probe, and Twig wraps
 * anything a template throws in a `RuntimeError` carrying the original message
 * (`Template::yield()`), so that is what a consumer sees — the same shape
 * actions' `ModalsRenderTest` pins for its host-resolution throw.
 */
it('refuses an unshipped filters layout on render', function (FiltersLayout $layout): void {
    expect(static fn (): string => filtersTableHtml(
        filteredSongsTable(fn (Table $table): Table => $table->filters(songFilters(), $layout)),
    ))->toThrow(RuntimeError::class, '[' . $layout->name . '] filters layout');
})->with([
    FiltersLayout::BeforeContent,
    FiltersLayout::BeforeContentCollapsible,
    FiltersLayout::AfterContent,
    FiltersLayout::AfterContentCollapsible,
]);

it('renders the above-content panel between the indicators and the table, with no trigger', function (): void {
    $crawler = new Crawler(filtersTableHtml(
        filteredSongsTable(fn (Table $table): Table => $table->filters(songFilters(), FiltersLayout::AboveContent)),
        ['plays' => ['value' => '200']],
    ));

    $panel = $crawler->filter('.sy-ta-filters-above-content-ctn > .sy-ta-filters');
    $blocks = $crawler->filter('.sy-ta-ctn > *')->each(static fn (Crawler $node): string => $node->attr('class') ?? '');

    expect($panel->count())->toBe(1)
        ->and($panel->filter('select.sy-fo-select')->count())->toBe(1)
        ->and($crawler->filter('.sy-ta-filters-trigger')->count())->toBe(0)
        ->and($crawler->filter('.sy-ta-header-toolbar')->count())->toBe(0)
        ->and($crawler->filter('details')->count())->toBe(0)
        ->and(array_search('sy-ta-filters-above-content-ctn', $blocks, true))
        ->toBe(array_search('sy-ta-filter-indicators', $blocks, true) + 1);
});

it('collapses the above-content panel into a details, open only while filters are active', function (): void {
    $configure = fn (Table $table): Table => $table->filters(songFilters(), FiltersLayout::AboveContentCollapsible);

    $unfiltered = new Crawler(filtersTableHtml(filteredSongsTable($configure)));
    $filtered = new Crawler(filtersTableHtml(filteredSongsTable($configure), ['plays' => ['value' => '200']]));

    $summary = $filtered->filter('details.sy-ta-filters-collapsible > summary.sy-ta-filters-summary');

    expect($unfiltered->filter('details.sy-ta-filters-collapsible')->attr('open'))->toBeNull()
        ->and($filtered->filter('details.sy-ta-filters-collapsible')->attr('open'))->not->toBeNull()
        ->and($summary->filter('span.sy-icon-btn')->count())->toBe(1)
        ->and($summary->filter('button')->count())->toBe(0)
        ->and(mb_trim($summary->filter('.sy-ta-filters-trigger-badge')->text()))->toBe('1')
        ->and($filtered->filter('details.sy-ta-filters-collapsible > .sy-ta-filters')->count())->toBe(1);
});

/*
 * The fixture paginates deliberately: the records-array lane renders no
 * `Pagination` at all, so "before the pagination" would be a claim about an
 * element that is not in the document — a below-content block moved *after* the
 * footer would still be the last child and the assertion would hold.
 */
it('renders the below-content panel after the table and before the pagination', function (): void {
    $crawler = new Crawler(filtersTableHtml(
        fn (Table $table): Table => $table
            ->records(function (int $page, int|string|null $recordsPerPage): Paginator {
                assert(is_int($recordsPerPage));

                return new Paginator(
                    records: [['__key' => '1', 'title' => 'Airbag']],
                    totalRecordCount: null,
                    page: $page,
                    recordsPerPage: $recordsPerPage,
                    hasMoreRecords: true,
                );
            })
            ->columns([TextColumn::make('title')])
            ->paginated([2, 5])
            ->filters(songFilters(), FiltersLayout::BelowContent),
    ));

    $children = $crawler->filter('.sy-ta-ctn > *');
    $blocks = $children->each(static fn (Crawler $node): string => $node->nodeName());
    $classes = $children->each(static fn (Crawler $node): string => $node->attr('class') ?? '');

    expect($crawler->filter('.sy-ta-filters-below-content-ctn > .sy-ta-filters')->count())->toBe(1)
        ->and($crawler->filter('.sy-ta-filters-trigger')->count())->toBe(0)
        ->and($blocks)->toBe(['table', 'div', 'nav'])
        ->and($classes[1])->toBe('sy-ta-filters-below-content-ctn')
        ->and($classes[2])->toContain('sy-pagination');
});

it('renders the modal layout as a toolbar trigger carrying the invoker command for its dialog', function (): void {
    $crawler = new Crawler(filtersTableHtml(
        filteredSongsTable(fn (Table $table): Table => $table->filters(songFilters(), FiltersLayout::Modal)),
    ));

    $trigger = $crawler->filter('.sy-ta-header-toolbar-end .sy-ta-filters-trigger button.sy-icon-btn');
    $dialog = $crawler->filter('dialog.sy-modal');

    expect($trigger->count())->toBe(1)
        ->and($trigger->attr('data-action'))->toBeNull()
        ->and($trigger->attr('command'))->toBe('show-modal')
        ->and($trigger->attr('commandfor'))->toBe($dialog->attr('id'))
        ->and($crawler->filter('.sy-ta-filters-dropdown')->count())->toBe(0)
        ->and(mb_trim($dialog->filter('.sy-modal-heading')->text()))->toBe('Filters')
        ->and($dialog->filter('.sy-modal-content select.sy-fo-select')->count())->toBe(1)
        ->and($dialog->filter('.sy-modal-content .sy-ta-filters')->count())->toBe(0);
});

it('puts apply and reset in the filters modal footer', function (): void {
    $crawler = new Crawler(filtersTableHtml(
        filteredSongsTable(fn (Table $table): Table => $table->filters(songFilters(), FiltersLayout::Modal)),
    ));

    $footerButtons = $crawler->filter('dialog.sy-modal .sy-modal-footer-actions button.sy-btn');

    expect($footerButtons->count())->toBe(2)
        ->and($footerButtons->eq(0)->attr('data-live-action-param'))->toBe('applyTableFilters')
        ->and($footerButtons->eq(1)->attr('data-live-action-param'))->toBe('resetTableFiltersForm')
        // Both footer buttons are the small size the apply action declares.
        ->and($footerButtons->eq(0)->attr('class'))->toContain('sy-size-sm')
        ->and($footerButtons->eq(1)->attr('class'))->toContain('sy-size-sm');
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

/**
 * The selection lane: a two-record table whose bulk action turns the checkboxes
 * on, with the host's own selection prop filled where a test needs the selected
 * state the client would have written.
 *
 * @param list<string> $selectedKeys
 */
function selectionTableHtml(Closure $configureTable, array $selectedKeys = []): string
{
    $component = new TableHost(fn (Table $table): Table => $configureTable(
        $table
            ->records(fn (): array => [
                new Song('Airbag', 'OK Computer', id: 1),
                new Song('Let Down', 'OK Computer', id: 2),
            ])
            ->columns([TextColumn::make('title')]),
    ));

    $component->selectedTableRecordKeys = $selectedKeys;

    return renderTableHost($component);
}

it('attaches the selection controller to the table root with its five values', function (): void {
    $root = new Crawler(selectionTableHtml(
        fn (Table $table): Table => $table->toolbarActions([BulkAction::make('archive')]),
    ))->filter('div.sy.sy-ta');

    expect($root->attr('data-controller'))->toBe('sylament--tables--table')
        ->and($root->attr('data-sylament--tables--table-can-track-value'))->toBe('true')
        ->and($root->attr('data-sylament--tables--table-page-only-value'))->toBe('true')
        ->and($root->attr('data-sylament--tables--table-all-selectable-count-value'))->toBe('2')
        ->and($root->attr('data-sylament--tables--table-count-one-value'))->toBe('1 record selected')
        ->and($root->attr('data-sylament--tables--table-count-many-value'))->toBe(':count records selected');
});

it('renders the selection cells with the shared checkbox skin and the controller bindings', function (): void {
    $crawler = new Crawler(selectionTableHtml(
        fn (Table $table): Table => $table->toolbarActions([BulkAction::make('archive')]),
    ));

    $pageCheckbox = $crawler->filter('thead > tr > th.sy-ta-selection-cell > input.sy-checkbox.sy-ta-page-checkbox');
    $rowCheckboxes = $crawler->filter('tbody > tr.sy-ta-row > td.sy-ta-cell.sy-ta-selection-cell > input.sy-checkbox.sy-ta-record-checkbox');

    expect($pageCheckbox->count())->toBe(1)
        ->and($pageCheckbox->attr('type'))->toBe('checkbox')
        ->and($pageCheckbox->attr('data-sylament--tables--table-target'))->toBe('pageCheckbox')
        ->and($pageCheckbox->attr('data-action'))->toBe('change->sylament--tables--table#togglePage')
        ->and($rowCheckboxes->count())->toBe(2)
        ->and($rowCheckboxes->each(fn (Crawler $box): ?string => $box->attr('value')))->toBe(['1', '2'])
        ->and($rowCheckboxes->first()->attr('data-sylament--tables--table-target'))->toBe('recordCheckbox')
        ->and($rowCheckboxes->first()->attr('data-action'))->toBe('change->sylament--tables--table#toggleRecord')
        ->and($rowCheckboxes->first()->attr('checked'))->toBeNull()
        ->and($crawler->filter('thead > tr > th')->first()->attr('class'))->toBe('sy-ta-selection-cell');
});

it('renders the selection banner hidden with its count target and its two links', function (): void {
    $crawler = new Crawler(selectionTableHtml(
        fn (Table $table): Table => $table->toolbarActions([BulkAction::make('archive')]),
    ));

    $banner = $crawler->filter('div.sy-ta-ctn > div.sy-ta-selection-indicator');
    $links = $banner->filter('div.sy-ta-selection-indicator-actions-ctn > button.sy-link');

    expect($banner->count())->toBe(1)
        ->and($banner->attr('role'))->toBe('status')
        ->and($banner->attr('aria-live'))->toBe('polite')
        ->and($banner->attr('aria-atomic'))->toBe('true')
        ->and($banner->attr('hidden'))->not->toBeNull()
        ->and($banner->attr('data-sylament--tables--table-target'))->toBe('banner')
        ->and($banner->filter('span[data-sylament--tables--table-target="count"]')->count())->toBe(1)
        ->and($links->count())->toBe(2)
        ->and($links->eq(0)->attr('data-sylament--tables--table-target'))->toBe('selectAll')
        ->and($links->eq(0)->attr('data-action'))->toBe('click->sylament--tables--table#selectAll')
        ->and($links->eq(0)->attr('hidden'))->not->toBeNull()
        ->and(mb_trim($links->eq(0)->text()))->toBe('Select all 2')
        ->and($links->eq(1)->attr('data-sylament--tables--table-target'))->toBe('deselectAll')
        ->and($links->eq(1)->attr('data-action'))->toBe('click->sylament--tables--table#deselectAll')
        ->and(mb_trim($links->eq(1)->text()))->toBe('Deselect all');
});

it('unhides the banner and the bulk trigger once a record is selected', function (): void {
    $crawler = new Crawler(selectionTableHtml(
        fn (Table $table): Table => $table->toolbarActions([BulkAction::make('archive')]),
        selectedKeys: ['1'],
    ));

    $bulkTrigger = $crawler->filter('.sy-ta-header-toolbar-actions > span[data-sy-table-bulk-only]');

    expect($crawler->filter('div.sy-ta-selection-indicator')->attr('hidden'))->toBeNull()
        ->and($bulkTrigger->count())->toBe(1)
        ->and($bulkTrigger->attr('hidden'))->toBeNull()
        ->and($crawler->filter('tbody input.sy-ta-record-checkbox')->first()->attr('checked'))->not->toBeNull()
        ->and($crawler->filter('tbody input.sy-ta-record-checkbox')->last()->attr('checked'))->toBeNull();
});

it('renders header actions in the header block and the bulk trigger in the toolbar', function (): void {
    $crawler = new Crawler(selectionTableHtml(fn (Table $table): Table => $table
        ->headerActions([Action::make('create')->label('New song')])
        ->toolbarActions([BulkAction::make('archive')])
        ->searchable()));

    $header = $crawler->filter('.sy-ta-header');
    $headerActions = $header->filter('.sy-ta-header-actions');
    $toolbar = $crawler->filter('.sy-ta-header-toolbar');
    $start = $toolbar->filter('.sy-ta-header-toolbar-actions');
    $wrappedTrigger = $start->filter('span[data-sy-table-bulk-only]');

    expect($header->count())->toBe(1)
        ->and(mb_trim($headerActions->text()))->toBe('New song')
        ->and($headerActions->filter('[data-sy-table-bulk-only]')->count())->toBe(0)
        ->and($toolbar->children()->count())->toBe(2)
        ->and($toolbar->children()->eq(0)->attr('class'))->toBe('sy-ta-header-toolbar-actions')
        ->and($toolbar->children()->eq(1)->attr('class'))->toBe('sy-ta-header-toolbar-end')
        ->and($start->children()->count())->toBe(1)
        ->and($wrappedTrigger->count())->toBe(1)
        ->and($wrappedTrigger->attr('hidden'))->not->toBeNull()
        ->and($wrappedTrigger->filter('button[data-sy-table-bulk-only]')->count())->toBe(1);
});

it('renders an empty selection cell for a record the table refuses', function (): void {
    $crawler = new Crawler(selectionTableHtml(fn (Table $table): Table => $table
        ->toolbarActions([BulkAction::make('archive')])
        ->checkIfRecordIsSelectableUsing(fn (Song $record): bool => $record->id !== 2)));

    $cells = $crawler->filter('tbody > tr.sy-ta-row > td.sy-ta-selection-cell');

    expect($cells->count())->toBe(2)
        ->and($cells->eq(0)->filter('input')->count())->toBe(1)
        ->and($cells->eq(1)->filter('input')->count())->toBe(0)
        ->and($crawler->filter('div.sy.sy-ta')->attr('data-sylament--tables--table-all-selectable-count-value'))->toBe('1');
});

it('moves the selection cells behind the columns for the AfterCells position', function (): void {
    $crawler = new Crawler(selectionTableHtml(fn (Table $table): Table => $table
        ->toolbarActions([BulkAction::make('archive')])
        ->recordCheckboxPosition(RecordCheckboxPosition::AfterCells)));

    expect($crawler->filter('thead > tr > th')->first()->attr('class'))->toContain('sy-ta-header-cell')
        ->and($crawler->filter('thead > tr > th')->last()->attr('class'))->toBe('sy-ta-selection-cell')
        ->and($crawler->filter('tbody > tr.sy-ta-row')->first()->filter('td')->last()->attr('class'))->toBe('sy-ta-cell sy-ta-selection-cell');
});

it('renders no selection chrome when selectable(false) overrides the bulk action', function (): void {
    $html = selectionTableHtml(fn (Table $table): Table => $table
        ->toolbarActions([BulkAction::make('archive')])
        ->selectable(false));

    expect($html)->not->toContain('sy-ta-selection-cell')
        ->and($html)->not->toContain('sy-ta-selection-indicator')
        ->and($html)->not->toContain('sylament--tables--table')
        ->and($html)->toContain('sy-ta-header-toolbar-actions');
});

it('renders no selection controller and no toolbar for a table with no toolbar actions', function (): void {
    $html = selectionTableHtml(fn (Table $table): Table => $table);

    expect($html)->not->toContain('sylament--tables--table')
        ->and($html)->not->toContain('sy-ta-selection-cell')
        ->and($html)->not->toContain('sy-ta-selection-indicator')
        ->and($html)->not->toContain('sy-ta-header-toolbar');
});

it('keeps the header-label list free of the selection column', function (): void {
    $crawler = new Crawler(selectionTableHtml(
        fn (Table $table): Table => $table->toolbarActions([BulkAction::make('archive')]),
    ));

    expect($crawler->filter('th.sy-ta-header-cell')->each(fn (Crawler $header): string => $header->text()))
        ->toBe(['Title']);
});

it('marks a bulk action group on its dropdown root and leaves a plain group unwrapped', function (): void {
    $crawler = new Crawler(selectionTableHtml(fn (Table $table): Table => $table->toolbarActions([
        BulkActionGroup::make([BulkAction::make('archive')])->icon(null),
        ActionGroup::make([Action::make('export')])->icon(null),
    ])));

    $start = $crawler->filter('.sy-ta-header-toolbar-actions');
    $wrapped = $start->filter('span[data-sy-table-bulk-only]');

    expect($start->children()->count())->toBe(2)
        ->and($wrapped->count())->toBe(1)
        ->and($wrapped->attr('hidden'))->not->toBeNull()
        ->and($wrapped->filter('div.sy.sy-dropdown[data-sy-table-bulk-only]')->count())->toBe(1)
        ->and($start->children()->eq(1)->attr('data-sy-table-bulk-only'))->toBeNull()
        ->and($start->children()->eq(1)->filter('[data-sy-table-bulk-only]')->count())->toBe(0);
});

/**
 * The row-behaviour lane: two records, whatever the caller configures on top,
 * and the host's selection prop filled where a test needs a server-painted tint.
 *
 * @param list<string> $selectedKeys
 */
function rowBehaviourTableHtml(Closure $configureTable, array $selectedKeys = []): string
{
    $component = new TableHost(fn (Table $table): Table => $configureTable(
        $table
            ->records(fn (): array => [
                new Song('Airbag', 'OK Computer', id: 1),
                new Song('Let Down', 'OK Computer', id: 2),
            ])
            ->columns([TextColumn::make('title')]),
    ));

    $component->selectedTableRecordKeys = $selectedKeys;

    return renderTableHost($component);
}

it('wraps every cell in a div and leaves the row unmarked without a url or an action', function (): void {
    $crawler = new Crawler(rowBehaviourTableHtml(fn (Table $table): Table => $table));

    expect($crawler->filter('tbody > tr.sy-ta-row')->count())->toBe(2)
        ->and($crawler->filter('tbody > tr > td.sy-ta-cell > div.sy-ta-col')->count())->toBe(2)
        ->and($crawler->filter('tbody > tr.sy-clickable')->count())->toBe(0)
        ->and($crawler->filter('tbody > tr > td a, tbody > tr > td button')->count())->toBe(0);
});

it('wraps the cell in an anchor for a record url and marks the row clickable', function (): void {
    $crawler = new Crawler(rowBehaviourTableHtml(fn (Table $table): Table => $table
        ->recordUrl(fn (Song $record): string => '/songs/' . $record->id)));

    $anchors = $crawler->filter('tbody > tr > td.sy-ta-cell > a.sy-ta-col');

    expect($crawler->filter('tbody > tr.sy-ta-row.sy-clickable')->count())->toBe(2)
        ->and($anchors->count())->toBe(2)
        ->and($anchors->each(fn (Crawler $anchor): ?string => $anchor->attr('href')))->toBe(['/songs/1', '/songs/2'])
        ->and($anchors->first()->attr('target'))->toBeNull()
        ->and(mb_trim($anchors->first()->text()))->toBe('Airbag');
});

it('opens the record anchor in a new tab and stamps the extra link attributes', function (): void {
    $crawler = new Crawler(rowBehaviourTableHtml(fn (Table $table): Table => $table
        ->recordUrl('/songs', shouldOpenInNewTab: true)
        ->extraRecordLinkAttributes(fn (Song $record): array => ['data-song' => $record->title, 'class' => 'sy-probe'])));

    $anchor = $crawler->filter('tbody > tr > td.sy-ta-cell > a.sy-ta-col')->first();

    expect($anchor->attr('target'))->toBe('_blank')
        ->and($anchor->attr('data-song'))->toBe('Airbag')
        ->and($anchor->attr('class'))->toBe('sy-ta-col sy-probe');
});

it('keeps the sanitized url and the new-tab target over extra link attributes naming either', function (): void {
    $overriding = fn (Table $table): Table => $table
        ->extraRecordLinkAttributes(['href' => 'javascript:alert(1)', 'target' => '_top']);

    $newTab = new Crawler(rowBehaviourTableHtml(fn (Table $table): Table => $overriding($table->recordUrl('/songs', shouldOpenInNewTab: true))));
    $sameTab = new Crawler(rowBehaviourTableHtml(fn (Table $table): Table => $overriding($table->recordUrl('/songs'))));

    // The author's bag is spread first, so neither key can route around the
    // sanitizer — and the same-tab arm renders no target of its own, which is
    // what leaves the author's one standing.
    expect($newTab->filter('a.sy-ta-col')->first()->attr('href'))->toBe('/songs')
        ->and($newTab->filter('a.sy-ta-col')->first()->attr('target'))->toBe('_blank')
        ->and($sameTab->filter('a.sy-ta-col')->first()->attr('href'))->toBe('/songs')
        ->and($sameTab->filter('a.sy-ta-col')->first()->attr('target'))->toBe('_top');
});

it('evaluates the extra record link attributes once per record however many columns render', function (): void {
    $calls = 0;

    $crawler = new Crawler(rowBehaviourTableHtml(function (Table $table) use (&$calls): Table {
        return $table
            ->columns([TextColumn::make('title'), TextColumn::make('album')])
            ->recordUrl('/songs')
            ->extraRecordLinkAttributes(function () use (&$calls): array {
                $calls++;

                return ['data-probe' => 'yes'];
            });
    }));

    expect($crawler->filter('tbody > tr > td.sy-ta-cell > a.sy-ta-col[data-probe="yes"]')->count())->toBe(4)
        ->and($calls)->toBe(2);
});

it('drops a record url the sanitizer refuses back to a plain div', function (): void {
    $crawler = new Crawler(rowBehaviourTableHtml(fn (Table $table): Table => $table
        ->recordUrl('javascript:alert(1)')));

    expect($crawler->filter('tbody > tr > td.sy-ta-cell > div.sy-ta-col')->count())->toBe(2)
        ->and($crawler->filter('tbody > tr.sy-clickable')->count())->toBe(0);
});

it('wraps the cell in a button mounting a registered record action', function (): void {
    $crawler = new Crawler(rowBehaviourTableHtml(fn (Table $table): Table => $table
        ->recordActions([Action::make('view')])
        ->recordAction('view')));

    $button = $crawler->filter('tbody > tr > td.sy-ta-cell > button.sy-ta-col')->first();

    expect($crawler->filter('tbody > tr.sy-ta-row.sy-clickable')->count())->toBe(2)
        ->and($button->attr('type'))->toBe('button')
        ->and($button->attr('data-loading'))->toBe('addAttribute(disabled)')
        ->and($button->attr('data-action'))->toContain('live#action')
        ->and($button->attr('data-live-action-param'))->toBe('mountAction')
        ->and($button->attr('data-live-name-param'))->toBe('view')
        ->and($button->attr('data-live-context-param'))->toBe('{"recordKey":"1","table":true}');
});

it('calls an unregistered record action name straight on the host with the record key', function (): void {
    $crawler = new Crawler(rowBehaviourTableHtml(fn (Table $table): Table => $table->recordAction('openMyThing')));

    $button = $crawler->filter('tbody > tr > td.sy-ta-cell > button.sy-ta-col')->last();

    expect($button->attr('data-live-action-param'))->toBe('openMyThing')
        ->and($button->attr('data-live-record-key-param'))->toBe('2')
        ->and($button->attr('data-live-name-param'))->toBeNull();
});

it('lets the record url beat the record action', function (): void {
    $crawler = new Crawler(rowBehaviourTableHtml(fn (Table $table): Table => $table
        ->recordActions([Action::make('view')])
        ->recordAction('view')
        ->recordUrl('/songs')));

    expect($crawler->filter('tbody > tr > td.sy-ta-cell > a.sy-ta-col')->count())->toBe(2)
        ->and($crawler->filter('tbody > tr > td.sy-ta-cell > button.sy-ta-col')->count())->toBe(0);
});

it('keeps the checkbox and the actions cells outside every wrapper', function (): void {
    $crawler = new Crawler(rowBehaviourTableHtml(fn (Table $table): Table => $table
        ->recordUrl('/songs')
        ->recordActions([Action::make('edit')])
        ->toolbarActions([BulkAction::make('archive')])));

    $row = $crawler->filter('tbody > tr.sy-ta-row')->first();

    expect($row->filter('td.sy-ta-selection-cell > input.sy-ta-record-checkbox')->count())->toBe(1)
        ->and($row->filter('td > div.sy-ta-actions')->count())->toBe(1)
        ->and($row->filter('a input, a .sy-ta-actions, button input, button .sy-ta-actions')->count())->toBe(0);
});

it('stripes the odd rows only when striping is on', function (): void {
    $striped = new Crawler(rowBehaviourTableHtml(fn (Table $table): Table => $table->striped()));
    $plain = new Crawler(rowBehaviourTableHtml(fn (Table $table): Table => $table));

    expect($striped->filter('tbody > tr')->first()->attr('class'))->toBe('sy-ta-row')
        ->and($striped->filter('tbody > tr')->last()->attr('class'))->toBe('sy-ta-row sy-striped')
        ->and($plain->filter('tbody > tr.sy-striped')->count())->toBe(0);
});

it('appends the record classes after ours, from a string, a list and a map', function (): void {
    $fromString = new Crawler(rowBehaviourTableHtml(fn (Table $table): Table => $table->recordClasses('sy-one sy-two')));
    $fromList = new Crawler(rowBehaviourTableHtml(fn (Table $table): Table => $table->recordClasses(['sy-one', 'sy-two'])));
    $fromMap = new Crawler(rowBehaviourTableHtml(fn (Table $table): Table => $table->recordClasses(
        fn (Song $record): array => ['sy-first' => $record->id === 1, 'sy-second' => $record->id === 2],
    )));

    expect($fromString->filter('tbody > tr')->first()->attr('class'))->toBe('sy-ta-row sy-one sy-two')
        ->and($fromList->filter('tbody > tr')->first()->attr('class'))->toBe('sy-ta-row sy-one sy-two')
        ->and($fromMap->filter('tbody > tr')->first()->attr('class'))->toBe('sy-ta-row sy-first')
        ->and($fromMap->filter('tbody > tr')->last()->attr('class'))->toBe('sy-ta-row sy-second');
});

it('paints the selected tint on the server for a selected key', function (): void {
    $crawler = new Crawler(rowBehaviourTableHtml(
        fn (Table $table): Table => $table->toolbarActions([BulkAction::make('archive')]),
        selectedKeys: ['2'],
    ));

    expect($crawler->filter('tbody > tr')->first()->attr('class'))->toBe('sy-ta-row')
        ->and($crawler->filter('tbody > tr')->last()->attr('class'))->toBe('sy-ta-row sy-selected');
});

it('leaves the tint off a row the table refuses to select', function (): void {
    $crawler = new Crawler(rowBehaviourTableHtml(
        fn (Table $table): Table => $table
            ->toolbarActions([BulkAction::make('archive')])
            ->checkIfRecordIsSelectableUsing(fn (Song $record): bool => $record->id === 1),
        selectedKeys: ['1', '2'],
    ));

    expect($crawler->filter('tbody > tr')->first()->attr('class'))->toBe('sy-ta-row sy-selected')
        ->and($crawler->filter('tbody > tr')->last()->attr('class'))->toBe('sy-ta-row');
});

it('renders the visible empty state actions under the description', function (): void {
    $crawler = new Crawler(renderTableHost(new TableHost(fn (Table $table): Table => $table
        ->records(fn (): array => [])
        ->columns([TextColumn::make('title')])
        ->emptyStateActions([
            Action::make('create')->label('New song'),
            Action::make('import')->hidden(),
        ]))));

    $actions = $crawler->filter('.sy-ta-empty-state .sy-ta-empty-state-actions');

    expect($actions->count())->toBe(1)
        ->and($actions->filter('button.sy-ac')->count())->toBe(1)
        ->and(mb_trim($actions->filter('button.sy-ac')->text()))->toBe('New song')
        ->and($crawler->filter('.sy-ta-empty-state-description')->text())->toContain('Create a record to get started.');
});

it('replaces the whole empty state block with the custom markup', function (): void {
    $crawler = new Crawler(renderTableHost(new TableHost(fn (Table $table): Table => $table
        ->records(fn (): array => [])
        ->columns([TextColumn::make('title')])
        ->emptyState(new Markup('<p class="sy-probe">Nothing at all</p>', 'UTF-8'))
        ->emptyStateActions([Action::make('create')]))));

    expect($crawler->filter('p.sy-probe')->count())->toBe(1)
        ->and($crawler->filter('.sy-ta-empty-state')->count())->toBe(0)
        ->and($crawler->filter('.sy-ta-empty-state-actions')->count())->toBe(0);
});

it('demotes the empty state heading below a table heading', function (): void {
    $withHeading = new Crawler(renderTableHost(new TableHost(fn (Table $table): Table => $table
        ->records(fn (): array => [])
        ->columns([TextColumn::make('title')])
        ->heading('Songs'))));
    $without = new Crawler(renderTableHost(new TableHost(fn (Table $table): Table => $table
        ->records(fn (): array => [])
        ->columns([TextColumn::make('title')]))));

    expect($withHeading->filter('h3.sy-ta-empty-state-heading')->count())->toBe(1)
        ->and($without->filter('h2.sy-ta-empty-state-heading')->count())->toBe(1);
});

/*
 * The column lane of the same rig: the interleaved cell wrapper, and the header
 * and cell attribute assembly the column's own surface feeds.
 */

it('lets a column url beat the record url and marks the anchor as the columns own', function (): void {
    $crawler = new Crawler(rowBehaviourTableHtml(fn (Table $table): Table => $table
        ->columns([TextColumn::make('title')->url(fn (Song $record): string => '/columns/' . $record->id)])
        ->recordUrl('/records')));

    $anchor = $crawler->filter('tbody > tr > td.sy-ta-cell > a.sy-ta-col')->first();

    expect($anchor->attr('href'))->toBe('/columns/1')
        ->and($anchor->attr('class'))->toBe('sy-ta-col sy-ta-col-has-column-url');
});

it('lets a column action suppress the record url entirely', function (): void {
    $crawler = new Crawler(rowBehaviourTableHtml(fn (Table $table): Table => $table
        ->columns([TextColumn::make('title')->action(fn (): null => null)])
        ->recordUrl('/records')));

    expect($crawler->filter('tbody > tr > td.sy-ta-cell > a.sy-ta-col')->count())->toBe(0)
        ->and($crawler->filter('tbody > tr > td.sy-ta-cell > button.sy-ta-col')->count())->toBe(2);
});

it('lets a column url beat a record action', function (): void {
    $crawler = new Crawler(rowBehaviourTableHtml(fn (Table $table): Table => $table
        ->columns([TextColumn::make('title')->url('/columns')])
        ->recordAction('openMyThing')));

    expect($crawler->filter('tbody > tr > td.sy-ta-cell > a.sy-ta-col')->count())->toBe(2)
        ->and($crawler->filter('tbody > tr > td.sy-ta-cell > button.sy-ta-col')->count())->toBe(0);
});

it('collapses a click-disabled column to a div however the record is wired', function (): void {
    $crawler = new Crawler(rowBehaviourTableHtml(fn (Table $table): Table => $table
        ->columns([TextColumn::make('title')->url('/columns')->disabledClick()])
        ->recordUrl('/records')
        ->recordAction('openMyThing')));

    expect($crawler->filter('tbody > tr > td.sy-ta-cell > div.sy-ta-col')->count())->toBe(2)
        ->and($crawler->filter('tbody > tr > td a, tbody > tr > td button')->count())->toBe(0);
});

it('collapses a state-based column url to a div and leaves no wrapper href', function (): void {
    $crawler = new Crawler(rowBehaviourTableHtml(fn (Table $table): Table => $table
        ->columns([TextColumn::make('title')->url(fn (mixed $state): string => '/songs/' . $state)])
        ->recordUrl('/records')));

    expect($crawler->filter('tbody > tr > td.sy-ta-cell > div.sy-ta-col')->count())->toBe(2)
        ->and($crawler->filter('tbody > tr > td.sy-ta-cell > a.sy-ta-col')->count())->toBe(0);
});

it('takes the new-tab flag from whichever arm supplied the href', function (): void {
    $fromColumn = new Crawler(rowBehaviourTableHtml(fn (Table $table): Table => $table
        ->columns([TextColumn::make('title')->url('/columns', shouldOpenInNewTab: true)])
        ->recordUrl('/records')));
    $fromRecord = new Crawler(rowBehaviourTableHtml(fn (Table $table): Table => $table
        ->columns([TextColumn::make('title')->openUrlInNewTab()])
        ->recordUrl('/records')));

    expect($fromColumn->filter('a.sy-ta-col')->first()->attr('target'))->toBe('_blank')
        ->and($fromRecord->filter('a.sy-ta-col')->first()->attr('target'))->toBeNull();
});

it('spreads the record link attributes only on the record fallback', function (): void {
    $configure = fn (Table $table): Table => $table
        ->recordUrl('/records')
        ->extraRecordLinkAttributes(['data-probe' => 'yes']);

    $columnArm = new Crawler(rowBehaviourTableHtml(fn (Table $table): Table => $configure($table)
        ->columns([TextColumn::make('title')->url('/columns')])));
    $recordArm = new Crawler(rowBehaviourTableHtml($configure));

    expect($columnArm->filter('a.sy-ta-col')->first()->attr('data-probe'))->toBeNull()
        ->and($recordArm->filter('a.sy-ta-col')->first()->attr('data-probe'))->toBe('yes');
});

it('mounts a column action instance and calls a column action closure by name', function (): void {
    $mounting = new Crawler(rowBehaviourTableHtml(fn (Table $table): Table => $table
        ->columns([TextColumn::make('title')->action(Action::make('view'))])));
    $calling = new Crawler(rowBehaviourTableHtml(fn (Table $table): Table => $table
        ->columns([TextColumn::make('title')->action(fn (): null => null)])));

    $mounted = $mounting->filter('button.sy-ta-col')->first();
    $called = $calling->filter('button.sy-ta-col')->first();

    expect($mounted->attr('data-live-action-param'))->toBe('mountAction')
        ->and($mounted->attr('data-live-name-param'))->toBe('view')
        ->and($mounted->attr('data-live-context-param'))->toBe('{"recordKey":"1","table":true}')
        ->and($called->attr('data-live-action-param'))->toBe('callTableColumnAction')
        ->and($called->attr('data-live-name-param'))->toBe('title')
        ->and($called->attr('data-live-record-key-param'))->toBe('1');
});

it('assembles the header cell from the columns own surface', function (): void {
    $crawler = new Crawler(rowBehaviourTableHtml(fn (Table $table): Table => $table
        ->columns([
            TextColumn::make('releasedAt')
                ->wrapHeader()
                ->grow()
                ->alignCenter()
                ->hiddenFrom('md')
                ->visibleFrom('lg')
                ->headerTooltip('When it shipped')
                ->extraHeaderAttributes(['data-probe' => 'yes']),
        ])));

    $header = $crawler->filter('thead > tr > th')->first();

    expect($header->attr('class'))->toBe('sy-ta-header-cell sy-ta-header-cell-released-at sy-growable sy-wrapped sy-align-center md:sy-ta-hidden lg:sy-ta-visible')
        ->and($header->attr('title'))->toBe('When it shipped')
        ->and($header->attr('data-probe'))->toBe('yes')
        ->and($header->attr('style'))->toBeNull();
});

it('drops the growable marker for a column that declares a width, and styles the width instead', function (): void {
    $crawler = new Crawler(rowBehaviourTableHtml(fn (Table $table): Table => $table
        ->columns([TextColumn::make('title')->grow()->width(200)])));

    $header = $crawler->filter('thead > tr > th')->first();

    expect($header->attr('class'))->toBe('sy-ta-header-cell sy-ta-header-cell-title')
        ->and($header->attr('style'))->toBe('width: 200px');
});

it('flattens a markup header tooltip to text', function (): void {
    $crawler = new Crawler(rowBehaviourTableHtml(fn (Table $table): Table => $table
        ->columns([TextColumn::make('title')->headerTooltip(new Markup('<b>When</b> it shipped', 'UTF-8'))])));

    expect($crawler->filter('thead > tr > th')->first()->attr('title'))->toBe('When it shipped');
});

it('swaps the sort control to a span for a markup label and names all three events', function (): void {
    $markup = new Crawler(rowBehaviourTableHtml(fn (Table $table): Table => $table
        ->columns([TextColumn::make('title')->label(new Markup('<em>Title</em>', 'UTF-8'))->sortable()])));
    $plain = new Crawler(rowBehaviourTableHtml(fn (Table $table): Table => $table
        ->columns([TextColumn::make('title')->sortable()])));

    $span = $markup->filter('th span.sy-ta-header-cell-sort-btn')->first();

    expect($markup->filter('th button.sy-ta-header-cell-sort-btn')->count())->toBe(0)
        ->and($span->attr('role'))->toBe('button')
        ->and($span->attr('tabindex'))->toBe('0')
        ->and($span->attr('data-action'))->toBe('click->live#action keydown.enter->live#action keydown.space->live#action')
        ->and($span->attr('data-live-action-param'))->toBe('sortTable')
        ->and($span->filter('em')->text())->toBe('Title')
        ->and($plain->filter('th button.sy-ta-header-cell-sort-btn')->count())->toBe(1)
        ->and($plain->filter('th button.sy-ta-header-cell-sort-btn')->attr('data-action'))->toBe('live#action');
});

it('assembles the data cell from the columns own surface', function (): void {
    $crawler = new Crawler(rowBehaviourTableHtml(fn (Table $table): Table => $table
        ->columns([
            TextColumn::make('artist.name')
                ->alignEnd()
                ->verticallyAlignStart()
                ->hiddenFrom('sm')
                ->visibleFrom('2xl')
                ->extraCellAttributes(['data-probe' => 'yes']),
        ])));

    $cell = $crawler->filter('tbody > tr > td')->first();

    expect($cell->attr('class'))->toBe('sy-ta-cell sy-ta-cell-artist.name sy-align-end sy-vertical-align-start sm:sy-ta-hidden 2xl:sy-ta-visible')
        ->and($cell->attr('data-probe'))->toBe('yes');
});

it('hands each cell the row loop of the record being rendered', function (): void {
    $crawler = new Crawler(rowBehaviourTableHtml(fn (Table $table): Table => $table
        ->columns([TextColumn::make('title')->extraCellAttributes(fn (?stdClass $rowLoop): array => [
            'data-iteration' => (string) ($rowLoop?->iteration ?? ''),
            'data-count' => (string) ($rowLoop?->count ?? ''),
        ])])));

    $cells = $crawler->filter('tbody > tr > td.sy-ta-cell');

    expect($cells->each(fn (Crawler $cell): ?string => $cell->attr('data-iteration')))->toBe(['1', '2'])
        ->and($cells->first()->attr('data-count'))->toBe('2');
});

it('leaves a toggled-hidden column out of the header and every row', function (): void {
    $host = new TableHost(fn (Table $table): Table => $table
        ->records(fn (): array => [
            new Song('Airbag', 'OK Computer', id: 1),
            new Song('Let Down', 'OK Computer', id: 2),
        ])
        ->columns([TextColumn::make('title'), TextColumn::make('album')->toggleable()]));
    $host->tableColumns = [['type' => 'column', 'name' => 'album', 'isToggled' => false, 'isToggledHiddenByDefault' => false]];

    $crawler = new Crawler(renderTableHost($host));

    expect($crawler->filter('th.sy-ta-header-cell')->each(static fn (Crawler $header): string => mb_trim($header->text())))->toBe(['Title'])
        ->and($crawler->filter('tbody > tr')->count())->toBe(2)
        ->and($crawler->filter('tbody > tr > td.sy-ta-cell')->count())->toBe(2);
});

/*
 * The column manager's chrome: the toolbar trigger, the dropdown panel and the
 * modal layout beside it, the item list the host's state renders, and the two
 * places the reset action can sit. The state itself is the host's — these cases
 * mount it and then read what the partial and the two components make of it.
 */

function columnManagerTableHost(Closure $configureTable): TableHost
{
    $host = new TableHost(fn (Table $table): Table => $configureTable(
        $table
            ->records(fn (): array => [['__key' => '1', 'title' => 'Airbag', 'plays' => '100', 'album' => 'OK Computer']])
            ->columns([
                TextColumn::make('title'),
                TextColumn::make('plays')->toggleable(),
                TextColumn::make('album')->toggleable(isToggledHiddenByDefault: true),
            ]),
    ));
    $host->mountTableColumnManager();

    return $host;
}

function columnManagerTableHtml(Closure $configureTable, array $tableColumns = []): string
{
    $host = columnManagerTableHost($configureTable);

    if ($tableColumns !== []) {
        $host->applyTableColumnManager($tableColumns);
    }

    return renderTableHost($host);
}

it('renders the column manager trigger in the toolbar end group', function (): void {
    $crawler = new Crawler(columnManagerTableHtml(static fn (Table $table): Table => $table));

    $trigger = $crawler->filter('.sy-ta-header-toolbar-end button.sy-icon-btn[aria-label="Column manager"]');

    expect($trigger->count())->toBe(1)
        ->and($trigger->filter('svg.sy-icon')->count())->toBe(1);
});

it('offers the manager for reorderable columns alone and never under columnManager(false)', function (): void {
    $reorderable = new Crawler(columnManagerTableHtml(static fn (Table $table): Table => $table
        ->columns([TextColumn::make('title')])
        ->reorderableColumns()));
    $refused = new Crawler(columnManagerTableHtml(static fn (Table $table): Table => $table->columnManager(false)));

    expect($reorderable->filter('button.sy-icon-btn[aria-label="Column manager"]')->count())->toBe(1)
        ->and($refused->filter('button.sy-icon-btn[aria-label="Column manager"]')->count())->toBe(0);
});

/*
 * A reorder-only manager has nothing to tick, so the checkbox does not render at
 * all rather than rendering permanently disabled: an inert control the user can
 * never satisfy is worse than none, and gating the input is what Filament does.
 */
it('renders the manager rows without a checkbox when no column is toggleable', function (): void {
    $crawler = new Crawler(columnManagerTableHtml(static fn (Table $table): Table => $table
        ->columns([TextColumn::make('title'), TextColumn::make('plays')])
        ->reorderableColumns()));

    expect($crawler->filter('.sy-ta-col-manager-item')->count())->toBe(2)
        ->and($crawler->filter('.sy-ta-col-manager-checkbox')->count())->toBe(0)
        ->and($crawler->filter('.sy-ta-col-manager-reorder-handle')->count())->toBe(2)
        ->and($crawler->filter('.sy-ta-col-manager-item-label')->each(static fn (Crawler $label): string => mb_trim($label->text())))
        ->toBe(['Title', 'Plays']);
});

/*
 * The state is the host's and the chrome is the table's, so turning the chrome
 * off leaves every declared default standing: `album` is still hidden because it
 * asked to be, and nothing on the page can put it back.
 */
it('keeps the toggle defaults when the manager chrome is turned off', function (): void {
    $crawler = new Crawler(columnManagerTableHtml(static fn (Table $table): Table => $table->columnManager(false)));

    expect($crawler->filter('.sy-ta-col-manager-content')->count())->toBe(0)
        ->and($crawler->filter('th.sy-ta-header-cell')->each(static fn (Crawler $header): string => mb_trim($header->text())))
        ->toBe(['Title', 'Plays']);
});

it('opens the toolbar for a table whose only chrome is the column manager', function (): void {
    $crawler = new Crawler(columnManagerTableHtml(static fn (Table $table): Table => $table));

    $toolbar = $crawler->filter('.sy-ta-header-toolbar');

    expect($toolbar->count())->toBe(1)
        ->and($toolbar->filter('.sy-ta-search-field')->count())->toBe(0)
        ->and($toolbar->filter('.sy-ta-filters-trigger')->count())->toBe(0)
        ->and($toolbar->filter('.sy-ta-header-toolbar-actions')->count())->toBe(0);
});

it('teleports the column manager panel so a short table cannot clip it', function (): void {
    $crawler = new Crawler(columnManagerTableHtml(static fn (Table $table): Table => $table));

    expect($crawler->filter('.sy-ta-col-manager-dropdown')->attr('data-sylament--support--dropdown-teleport-value'))->toBe('true');
});

it('renders one manager item per unhidden column, keyed and checked from the state', function (): void {
    $host = columnManagerTableHost(static fn (Table $table): Table => $table);
    $hostId = $host->getTableHostId();

    $crawler = new Crawler(renderTableHost($host));
    $checkboxes = $crawler->filter('.sy-ta-col-manager-items .sy-ta-col-manager-item input.sy-ta-col-manager-checkbox');

    expect($checkboxes->each(static fn (Crawler $checkbox): ?string => $checkbox->attr('value')))->toBe(['title', 'plays', 'album'])
        ->and($checkboxes->each(static fn (Crawler $checkbox): ?string => $checkbox->attr('id')))->toBe([
            $hostId . '.table.column-manager.title',
            $hostId . '.table.column-manager.plays',
            $hostId . '.table.column-manager.album',
        ])
        ->and($checkboxes->each(static fn (Crawler $checkbox): bool => $checkbox->attr('checked') !== null))->toBe([true, true, false])
        ->and($checkboxes->each(static fn (Crawler $checkbox): bool => $checkbox->attr('disabled') !== null))->toBe([true, false, false])
        ->and($crawler->filter('.sy-ta-col-manager-item .sy-ta-col-manager-item-label')->each(static fn (Crawler $label): string => mb_trim($label->text())))
        ->toBe(['Title', 'Plays', 'Album']);
});

/*
 * `hidden()` is the developer's, so the column is not the user's to restore: it
 * leaves the manager, the header and every row at once. The cell counts are
 * exact because this fixture renders no record actions, no selection column and
 * no reorder handle — all three would also carry `sy-ta-cell`.
 */
it('leaves a hidden column out of the manager, the header and every row', function (): void {
    $crawler = new Crawler(columnManagerTableHtml(static fn (Table $table): Table => $table->columns([
        TextColumn::make('title'),
        TextColumn::make('plays')->toggleable(),
        TextColumn::make('album')->hidden(),
    ])));

    expect($crawler->filter('.sy-ta-col-manager-checkbox')->each(static fn (Crawler $checkbox): ?string => $checkbox->attr('value')))->toBe(['title', 'plays'])
        ->and($crawler->filter('th.sy-ta-header-cell')->count())->toBe(2)
        ->and($crawler->filter('tbody > tr > td.sy-ta-cell')->count())->toBe(2);
});

it('puts the reset action in the panel header by default and in its footer on request', function (): void {
    $header = new Crawler(columnManagerTableHtml(static fn (Table $table): Table => $table));
    $footer = new Crawler(columnManagerTableHtml(static fn (Table $table): Table => $table
        ->columnManagerResetActionPosition(ColumnManagerResetActionPosition::Footer)));

    expect(mb_trim($header->filter('.sy-ta-col-manager-header-actions button.sy-link')->text()))->toBe('Reset')
        ->and($header->filter('.sy-ta-col-manager-header-actions button.sy-link')->attr('data-live-action-param'))->toBe('resetTableColumnManager')
        ->and($footer->filter('.sy-ta-col-manager-header-actions')->count())->toBe(0)
        ->and(mb_trim($footer->filter('.sy-ta-col-manager-actions-ctn button.sy-btn')->last()->text()))->toBe('Reset');
});

it('renders the apply action only while the manager defers, and marks the panel live when it does not', function (): void {
    $deferred = new Crawler(columnManagerTableHtml(static fn (Table $table): Table => $table));
    $live = new Crawler(columnManagerTableHtml(static fn (Table $table): Table => $table->deferColumnManager(false)));

    expect(mb_trim($deferred->filter('.sy-ta-col-manager-actions-ctn button.sy-btn')->first()->text()))->toBe('Apply columns')
        ->and($deferred->filter('.sy-ta-col-manager-content')->attr('data-sylament--tables--column-manager-live-value'))->toBe('false')
        ->and($live->filter('.sy-ta-col-manager-actions-ctn')->count())->toBe(0)
        ->and($live->filter('.sy-ta-col-manager-content')->attr('data-sylament--tables--column-manager-live-value'))->toBe('true');
});

it('arms the drag control and its handles only for reorderable columns', function (): void {
    $static = new Crawler(columnManagerTableHtml(static fn (Table $table): Table => $table));
    $reorderable = new Crawler(columnManagerTableHtml(static fn (Table $table): Table => $table
        ->reorderableColumns()
        ->reorderAnimationDuration(120)));

    $items = $reorderable->filter('.sy-ta-col-manager-items');

    expect($static->filter('.sy-ta-col-manager-items')->attr('data-controller'))->toBeNull()
        ->and($static->filter('[data-sortable-item]')->count())->toBe(0)
        ->and($static->filter('.sy-ta-col-manager-reorder-handle')->count())->toBe(0)
        ->and($items->attr('data-controller'))->toContain('sylament--support--sortable')
        ->and($items->attr('data-sylament--support--sortable-animation-duration-value'))->toBe('120')
        ->and($items->attr('data-action'))->toContain('sylament--support--sortable:end->sylament--tables--column-manager#reorder')
        ->and($reorderable->filter('.sy-ta-col-manager-item')->each(static fn (Crawler $item): ?string => $item->attr('data-sortable-item')))
        ->toBe(['column::title', 'column::plays', 'column::album'])
        ->and($reorderable->filter('.sy-ta-col-manager-reorder-handle[data-sortable-handle]')->count())->toBe(3);
});

/*
 * `columnManagerColumns()` reaches the DOM as support's grid in its **column**
 * direction, which is what the stylesheet's `-mt-6` / `break-inside-avoid` trick
 * is built on — the row direction would swap it for a CSS grid and leave those
 * rules inert without failing anything else. A bare integer is support's
 * shorthand for that many columns from `lg` up and one below it, so the table
 * tier hands the count through untouched and the grid does the widening.
 */
it('lays the manager items out as a multi-column grid', function (): void {
    $items = new Crawler(columnManagerTableHtml(static fn (Table $table): Table => $table->columnManagerColumns(2)))
        ->filter('.sy-ta-col-manager-items');

    expect($items->attr('class'))->toContain('sy-grid-direction-col')
        ->and($items->attr('class'))->toContain('lg:sy-grid-cols')
        ->and($items->attr('style'))->toContain('--cols-lg: 2')
        ->and($items->attr('style'))->toContain('--cols-default: 1');
});

it('binds every manager checkbox to the controller through a target and a change action', function (): void {
    $crawler = new Crawler(columnManagerTableHtml(static fn (Table $table): Table => $table));

    $checkbox = $crawler->filter('.sy-ta-col-manager-checkbox')->first();

    expect($checkbox->attr('data-sylament--tables--column-manager-target'))->toBe('checkbox')
        ->and($checkbox->attr('data-action'))->toBe('change->sylament--tables--column-manager#toggle');
});

it('renders the manager modal layout as a toolbar trigger carrying the invoker command for its dialog', function (): void {
    $host = columnManagerTableHost(static fn (Table $table): Table => $table
        ->columnManagerLayout(ColumnManagerLayout::Modal));
    $hostId = $host->getTableHostId();

    $crawler = new Crawler(renderTableHost($host));
    $trigger = $crawler->filter('.sy-ta-header-toolbar-end button.sy-icon-btn[aria-label="Column manager"]');
    $dialog = $crawler->filter('dialog.sy-modal');

    expect($trigger->count())->toBe(1)
        ->and($trigger->attr('data-action'))->toBeNull()
        ->and($trigger->attr('command'))->toBe('show-modal')
        ->and($trigger->attr('commandfor'))->toBe($hostId . '.table.column-manager')
        ->and($dialog->attr('id'))->toBe($hostId . '.table.column-manager')
        ->and($crawler->filter('.sy-ta-col-manager-dropdown')->count())->toBe(0)
        ->and(mb_trim($dialog->filter('.sy-modal-heading')->text()))->toBe('Columns')
        ->and($dialog->filter('.sy-modal-content .sy-ta-col-manager-items .sy-ta-col-manager-checkbox')->count())->toBe(3)
        ->and($dialog->filter('.sy-modal-content .sy-ta-col-manager')->count())->toBe(0);
});

it('puts apply and reset in the manager modal footer', function (): void {
    $deferred = new Crawler(columnManagerTableHtml(static fn (Table $table): Table => $table
        ->columnManagerLayout(ColumnManagerLayout::Modal)));
    $live = new Crawler(columnManagerTableHtml(static fn (Table $table): Table => $table
        ->columnManagerLayout(ColumnManagerLayout::Modal)
        ->deferColumnManager(false)));

    $footerButtons = $deferred->filter('dialog.sy-modal .sy-modal-footer-actions button.sy-btn');

    expect($footerButtons->each(static fn (Crawler $button): string => mb_trim($button->text())))->toBe(['Apply columns', 'Reset'])
        ->and($footerButtons->last()->attr('data-live-action-param'))->toBe('resetTableColumnManager')
        ->and($live->filter('dialog.sy-modal .sy-modal-footer-actions button.sy-btn')->each(static fn (Crawler $button): string => mb_trim($button->text())))->toBe(['Reset']);
});

/*
 * The per-column search row: its gate, the field each searchable column carries,
 * the spacer cells that keep it aligned with the header, and the branch that
 * keeps the whole table on screen once a term matches nothing. The bag is the
 * host's — these cases fill it and read what the partial makes of it.
 */

/**
 * Two individually searchable columns and one plain one over two records, with
 * the host's own bag seeded as its mount hook would seed it.
 *
 * @param array<string, mixed> $columnSearches
 */
function columnSearchTableHost(Closure $configureTable, array $columnSearches = []): TableHost
{
    $host = new TableHost(static fn (Table $table): Table => $configureTable(
        $table
            ->records(static fn (): array => [
                new Song('Airbag', 'OK Computer', id: 1),
                new Song('Let Down', 'OK Computer', id: 2),
            ])
            ->columns([
                TextColumn::make('title')->searchable(isIndividual: true),
                TextColumn::make('album')->searchable(isIndividual: true),
                TextColumn::make('id'),
            ]),
    ));

    $host->tableColumnSearches = $columnSearches;
    $host->mountTableColumnSearches();

    return $host;
}

/**
 * @param array<string, mixed> $columnSearches
 */
function columnSearchTableHtml(Closure $configureTable, array $columnSearches = []): string
{
    return renderTableHost(columnSearchTableHost($configureTable, $columnSearches));
}

it('opens the body with a search row that is not a record row', function (): void {
    $crawler = new Crawler(columnSearchTableHtml(static fn (Table $table): Table => $table));

    $firstRow = $crawler->filter('tbody > tr')->first();

    expect($firstRow->attr('class'))->toBe('sy-ta-column-search-row')
        ->and($firstRow->attr('data-sortable-item'))->toBeNull()
        ->and($crawler->filter('tbody > tr')->count())->toBe(3)
        ->and($crawler->filter('tbody > tr.sy-ta-row')->count())->toBe(2)
        ->and($firstRow->filter('td')->count())->toBe(3)
        ->and($firstRow->filter('td')->each(static fn (Crawler $cell): ?string => $cell->attr('class')))->toBe([
            'sy-ta-cell sy-ta-individual-search-cell',
            'sy-ta-cell sy-ta-individual-search-cell',
            'sy-ta-cell',
        ])
        ->and($firstRow->filter('td.sy-ta-individual-search-cell label.sy-ta-search-field')->count())->toBe(2)
        ->and($firstRow->filter('td:not(.sy-ta-individual-search-cell) label.sy-ta-search-field')->count())->toBe(0);
});

it('binds each search field to its own nested model, labelled by the column', function (): void {
    $inputs = new Crawler(columnSearchTableHtml(
        static fn (Table $table): Table => $table,
        ['title' => ' Air ', 'album' => ''],
    ))->filter('tr.sy-ta-column-search-row input.sy-ta-search-input');

    expect($inputs->count())->toBe(2)
        ->and($inputs->each(static fn (Crawler $input): ?string => $input->attr('data-model')))->toBe([
            'debounce(500)|tableColumnSearches.title',
            'debounce(500)|tableColumnSearches.album',
        ])
        ->and($inputs->each(static fn (Crawler $input): ?string => $input->attr('aria-label')))->toBe([
            'Search Title',
            'Search Album',
        ])
        ->and($inputs->each(static fn (Crawler $input): ?string => $input->attr('value')))->toBe([' Air ', ''])
        ->and($inputs->first()->attr('type'))->toBe('search');
});

it('binds the search fields on change for a table that asks for it', function (): void {
    $inputs = new Crawler(columnSearchTableHtml(static fn (Table $table): Table => $table->searchOnBlur()))
        ->filter('tr.sy-ta-column-search-row input.sy-ta-search-input');

    expect($inputs->each(static fn (Crawler $input): ?string => $input->attr('data-model')))->toBe([
        'on(change)|tableColumnSearches.title',
        'on(change)|tableColumnSearches.album',
    ]);
});

it('flattens a markup column label into the search field label', function (): void {
    $inputs = new Crawler(columnSearchTableHtml(static fn (Table $table): Table => $table->columns([
        TextColumn::make('title')->label(new Markup('<span>Song <em>title</em></span>', 'UTF-8'))->searchable(isIndividual: true),
    ])))->filter('tr.sy-ta-column-search-row input.sy-ta-search-input');

    expect($inputs->attr('aria-label'))->toBe('Search Song title');
});

it('renders no search row when no column asks for an input of its own', function (): void {
    $html = columnSearchTableHtml(static fn (Table $table): Table => $table->columns([
        TextColumn::make('title')->searchable(),
        TextColumn::make('album'),
    ]));

    expect($html)->not->toContain('sy-ta-column-search-row')
        ->and(new Crawler($html)->filter('tbody > tr')->count())->toBe(2);
});

it('takes the search row away with the last unhidden individually searchable column', function (): void {
    $html = columnSearchTableHtml(static fn (Table $table): Table => $table->columns([
        TextColumn::make('title'),
        TextColumn::make('album')->searchable(isIndividual: true)->hidden(),
    ]));

    expect($html)->not->toContain('sy-ta-column-search-row');
});

it('keeps the search row for a column the user toggled off, spacers only', function (): void {
    $host = columnSearchTableHost(static fn (Table $table): Table => $table->columns([
        TextColumn::make('title'),
        TextColumn::make('album')->searchable(isIndividual: true)->toggleable(),
    ]));
    $host->tableColumns = [['type' => 'column', 'name' => 'album', 'isToggled' => false, 'isToggledHiddenByDefault' => false]];

    $searchRow = new Crawler(renderTableHost($host))->filter('tr.sy-ta-column-search-row');

    expect($searchRow->count())->toBe(1)
        ->and($searchRow->filter('td')->count())->toBe(1)
        ->and($searchRow->filter('td')->attr('class'))->toBe('sy-ta-cell')
        ->and($searchRow->filter('input')->count())->toBe(0);
});

it('carries the responsive breakpoint classes the header cell carries', function (): void {
    $crawler = new Crawler(columnSearchTableHtml(static fn (Table $table): Table => $table->columns([
        TextColumn::make('title')->searchable(isIndividual: true)->hiddenFrom('md'),
        TextColumn::make('album')->visibleFrom('lg'),
    ])));

    $searchCells = $crawler->filter('tr.sy-ta-column-search-row > td');

    expect($crawler->filter('thead > tr > th')->count())->toBe($searchCells->count())
        ->and($searchCells->eq(0)->attr('class'))->toBe('sy-ta-cell sy-ta-individual-search-cell md:sy-ta-hidden')
        ->and($searchCells->eq(1)->attr('class'))->toBe('sy-ta-cell lg:sy-ta-visible')
        ->and($crawler->filter('thead > tr > th')->eq(0)->attr('class'))->toContain('md:sy-ta-hidden')
        ->and($crawler->filter('thead > tr > th')->eq(1)->attr('class'))->toContain('lg:sy-ta-visible');
});

it('matches the header cell count with leading record actions and a trailing checkbox', function (): void {
    $crawler = new Crawler(columnSearchTableHtml(static fn (Table $table): Table => $table
        ->recordActions([Action::make('edit')])
        ->recordActionsPosition(RecordActionsPosition::BeforeCells)
        ->toolbarActions([BulkAction::make('archive')])
        ->recordCheckboxPosition(RecordCheckboxPosition::AfterCells)));

    $searchRow = $crawler->filter('tr.sy-ta-column-search-row');

    expect($searchRow->filter('td')->count())->toBe($crawler->filter('thead > tr > th')->count())
        ->and($searchRow->filter('td')->count())->toBe(5)
        ->and($searchRow->filter('td')->first()->attr('class'))->toBe('sy-ta-cell')
        ->and($searchRow->filter('td')->last()->attr('class'))->toBe('sy-ta-cell')
        ->and($searchRow->filter('input.sy-ta-search-input')->count())->toBe(2);
});

/*
 * The search cells are the one per-column element this epic makes mobile — a
 * reorder or a toggle changes their order and their number — and idiomorph
 * pairs unkeyed siblings by position, which would leave a dirty input bound to
 * another column's model. Both the cell and its input carry the host-scoped key
 * the record cell already carries.
 */
it('keys every search cell and its input by column name', function (): void {
    $host = columnSearchTableHost(static fn (Table $table): Table => $table);
    $hostId = $host->getTableHostId();
    $searchRow = new Crawler(renderTableHost($host))->filter('tr.sy-ta-column-search-row');

    expect($searchRow->filter('td')->each(static fn (Crawler $cell): ?string => $cell->attr('id')))
        ->toBe([
            $hostId . '.table.column-search.title',
            $hostId . '.table.column-search.album',
            $hostId . '.table.column-search.id',
        ])
        ->and($searchRow->filter('input.sy-ta-search-input')->each(static fn (Crawler $input): ?string => $input->attr('id')))
        ->toBe([
            $hostId . '.table.column-search.title.input',
            $hostId . '.table.column-search.album.input',
        ]);
});

/*
 * The chrome spacers were pinned in their two non-default positions only, which
 * left the arms an ordinary table actually renders — record actions after the
 * columns, the checkbox before the cells — free to be dropped without failing
 * anything.
 */
it('matches the header cell count in the default action and checkbox positions', function (): void {
    $crawler = new Crawler(columnSearchTableHtml(static fn (Table $table): Table => $table
        ->recordActions([Action::make('edit')])
        ->toolbarActions([BulkAction::make('archive')])));

    $searchRow = $crawler->filter('tr.sy-ta-column-search-row');

    expect($searchRow->filter('td')->count())->toBe($crawler->filter('thead > tr > th')->count())
        ->and($searchRow->filter('td')->count())->toBe(5)
        ->and($searchRow->filter('td')->first()->attr('class'))->toBe('sy-ta-cell')
        ->and($searchRow->filter('td')->last()->attr('class'))->toBe('sy-ta-cell');
});

/*
 * The trailing half of the header's records gate had no case at all: it only
 * matters when a table has zero records AND puts its checkbox after the cells,
 * and every zero-records case in this file uses the default leading position.
 * Without it the header keeps a selection cell the search row has already
 * dropped, and every input sits one cell left of its column.
 */
it('drops the trailing header spacers with the records, so the search row still matches', function (): void {
    $crawler = new Crawler(columnSearchTableHtml(static fn (Table $table): Table => $table
        ->records(static fn (): array => [])
        ->toolbarActions([BulkAction::make('archive')])
        ->recordCheckboxPosition(RecordCheckboxPosition::AfterCells)));

    $searchRow = $crawler->filter('tr.sy-ta-column-search-row');

    expect($searchRow->filter('td')->count())->toBe($crawler->filter('thead > tr > th')->count())
        ->and($searchRow->filter('td')->count())->toBe(3)
        ->and($crawler->filter('thead > tr > th.sy-ta-selection-cell')->count())->toBe(0);
});

it('collapses the leading spacers to one while the records are being reordered', function (): void {
    $host = columnSearchTableHost(static fn (Table $table): Table => $table
        ->reorderable('id')
        ->toolbarActions([BulkAction::make('archive')]));
    $host->isTableReordering = true;

    $crawler = new Crawler(renderTableHost($host));
    $searchRow = $crawler->filter('tr.sy-ta-column-search-row');

    expect($searchRow->filter('td')->count())->toBe($crawler->filter('thead > tr > th')->count())
        ->and($searchRow->filter('td')->count())->toBe(4)
        ->and($searchRow->filter('td')->first()->attr('class'))->toBe('sy-ta-cell');
});

it('keeps the table, its header and the search row when a term matches nothing', function (): void {
    $html = columnSearchTableHtml(static fn (Table $table): Table => $table->records(static fn (): array => []));
    $crawler = new Crawler($html);

    expect($crawler->filter('table.sy-ta-table')->count())->toBe(1)
        ->and($crawler->filter('thead > tr > th')->count())->toBe(3)
        ->and($crawler->filter('tbody > tr')->count())->toBe(1)
        ->and($crawler->filter('tbody > tr.sy-ta-column-search-row input.sy-ta-search-input')->count())->toBe(2)
        ->and($crawler->filter('.sy-ta-empty-state')->count())->toBe(1)
        ->and(mb_strpos($html, '</table>'))->toBeLessThan((int) mb_strpos($html, 'sy-ta-empty-state'));
});

it('drops the header spacers with the records so the search row still matches', function (): void {
    $crawler = new Crawler(columnSearchTableHtml(static fn (Table $table): Table => $table
        ->records(static fn (): array => [])
        ->recordActions([Action::make('edit')])
        ->toolbarActions([BulkAction::make('archive')])));

    $searchRow = $crawler->filter('tr.sy-ta-column-search-row');

    expect($crawler->filter('thead > tr > th')->count())->toBe(3)
        ->and($searchRow->filter('td')->count())->toBe(3)
        ->and($crawler->filter('thead > tr > th.sy-ta-selection-cell')->count())->toBe(0);
});

it('drops the reorder header cell with the records too', function (): void {
    $host = columnSearchTableHost(static fn (Table $table): Table => $table
        ->records(static fn (): array => [])
        ->reorderable('id'));
    $host->isTableReordering = true;

    $crawler = new Crawler(renderTableHost($host));

    expect($crawler->filter('thead > tr > th')->count())->toBe(3)
        ->and($crawler->filter('thead > tr > th.sy-ta-reorder-header-cell')->count())->toBe(0)
        ->and($crawler->filter('tr.sy-ta-column-search-row > td')->count())->toBe(3);
});

it('swaps the whole table for the empty state when no column is individually searchable', function (): void {
    $crawler = new Crawler(columnSearchTableHtml(static fn (Table $table): Table => $table
        ->records(static fn (): array => [])
        ->columns([TextColumn::make('title')])));

    expect($crawler->filter('table.sy-ta-table')->count())->toBe(0)
        ->and($crawler->filter('.sy-ta-empty-state')->count())->toBe(1);
});

it('renders the loading block alone while the deferred window is open', function (): void {
    $host = columnSearchTableHost(static fn (Table $table): Table => $table->deferLoading());

    $crawler = new Crawler(renderTableHost($host));

    expect($crawler->filter('.sy-ta-table-loading-ctn')->count())->toBe(1)
        ->and($crawler->filter('table.sy-ta-table')->count())->toBe(0)
        ->and($crawler->filter('.sy-ta-column-search-row')->count())->toBe(0)
        ->and($crawler->filter('.sy-ta-empty-state')->count())->toBe(0);
});

it('renders no toolbar for a table whose only search is per column', function (): void {
    $crawler = new Crawler(columnSearchTableHtml(static fn (Table $table): Table => $table->columns([
        TextColumn::make('title')->searchable(isIndividual: true, isGlobal: false),
    ])));

    expect($crawler->filter('.sy-ta-header-toolbar')->count())->toBe(0)
        ->and($crawler->filter('tr.sy-ta-column-search-row input.sy-ta-search-input')->count())->toBe(1);
});
