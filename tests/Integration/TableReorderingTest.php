<?php

declare(strict_types=1);

namespace Sylament\Tables\Tests\Integration;

use Closure;
use Sylament\Actions\Action;
use Sylament\Actions\BulkAction;
use Sylament\Support\Icons\IconManager;
use Sylament\Tables\Columns\Summarizers\Count;
use Sylament\Tables\Columns\TextColumn;
use Sylament\Tables\Table;
use Sylament\Tables\Tests\Fixtures\Concerns\TableHost;
use Sylament\Tables\Twig\TablesIconAlias;
use Symfony\Component\DomCrawler\Crawler;

use function Sylament\Tables\Tests\renderToHtml;

/*
 * Reorder mode as the partial renders it: the trigger in the toolbar's start
 * group, the drag handle and the sortable mount, and everything a drag must not
 * compete with — the selection cells, the record and toolbar actions, the
 * sortable headers and every cell link — suppressed while the mode is on. Each
 * suppression is asserted against the same table with the mode off, so deleting
 * a gate fails here rather than only in a browser.
 */

/**
 * A three-row reorderable table, rendered with the mode off or on.
 */
function reorderingTableHtml(bool $isReordering = false, ?Closure $configure = null): string
{
    $host = new TableHost(fn (Table $table): Table => ($configure ?? static fn (Table $table): Table => $table)(
        $table
            ->records(fn (): array => [
                ['__key' => 'a', 'title' => 'Airbag'],
                ['__key' => 'b', 'title' => 'Bones'],
                ['__key' => 'c', 'title' => 'Creep'],
            ])
            ->columns([TextColumn::make('title')->sortable()])
            ->striped()
            ->reorderable('position'),
    ));

    $host->isTableReordering = $isReordering;

    return renderToHtml($host->getTable());
}

it('renders the reorder trigger only when the table is reorderable', function (): void {
    $trigger = new Crawler(reorderingTableHtml())->filter('.sy-ta-header-toolbar-actions button.sy-icon-btn');

    expect($trigger->count())->toBe(1)
        ->and($trigger->attr('aria-label'))->toBe('Reorder records')
        ->and($trigger->attr('data-live-action-param'))->toBe('toggleTableReordering')
        ->and($trigger->filter('svg.sy-icon')->count())->toBe(1);

    $unreorderable = new Crawler(reorderingTableHtml(configure: fn (Table $table): Table => $table->reorderable()));

    expect($unreorderable->filter('.sy-ta-header-toolbar-actions')->count())->toBe(0);
});

it('flips the trigger label while the mode is on', function (): void {
    $trigger = new Crawler(reorderingTableHtml(true))->filter('.sy-ta-header-toolbar-actions button.sy-icon-btn');

    expect($trigger->attr('aria-label'))->toBe('Finish reordering records')
        ->and($trigger->attr('data-live-action-param'))->toBe('toggleTableReordering');
});

it('lets a registered alias replace the trigger icon at render time', function (): void {
    $this::getContainer()->get(IconManager::class)->register([
        TablesIconAlias::ACTIONS_ENABLE_REORDERING => 'heroicons:x-mark-20-solid',
    ]);

    $icon = new Crawler(reorderingTableHtml())->filter('.sy-ta-header-toolbar-actions button.sy-icon-btn svg');

    // The override fixture is a 20-unit icon; the shipped `heroicons:arrows-up-down` default is 24.
    expect($icon->count())->toBe(1)
        ->and($icon->attr('viewBox'))->toBe('0 0 20 20');
});

it('mounts the drag control and marks the rows only while reordering', function (): void {
    $off = new Crawler(reorderingTableHtml());
    $on = new Crawler(reorderingTableHtml(true));

    expect($off->filter('tbody[data-controller~="sylament--support--sortable"]')->count())->toBe(0)
        ->and($off->filter('tr[data-sortable-item]')->count())->toBe(0)
        ->and($off->filter('[data-sortable-handle]')->count())->toBe(0);

    $tbody = $on->filter('tbody[data-controller~="sylament--support--sortable"]');

    expect($tbody->count())->toBe(1)
        ->and($tbody->attr('data-sylament--support--sortable-animation-duration-value'))->toBe('300')
        ->and($tbody->attr('data-action'))->toContain('sylament--support--sortable:end->sylament--tables--table#reorder')
        ->and($on->filter('tr[data-sortable-item]')->count())->toBe(3)
        ->and($on->filter('tr[data-sortable-item]')->first()->attr('data-sortable-item'))->toBe('a');
});

it('leads every row with a labelled drag handle while reordering', function (): void {
    $crawler = new Crawler(reorderingTableHtml(true));
    $handle = $crawler->filter('td.sy-ta-reorder-handle-cell button.sy-ta-reorder-handle');

    expect($crawler->filter('table.sy-ta-table-reordering')->count())->toBe(1)
        ->and($crawler->filter('thead th')->first()->attr('class'))->toContain('sy-ta-reorder-header-cell')
        ->and($handle->count())->toBe(3)
        ->and($handle->first()->attr('data-sortable-handle'))->toBe('')
        ->and($handle->first()->attr('aria-label'))->toBe('Reorder item a')
        ->and($handle->first()->filter('svg.sy-icon')->count())->toBe(1);
});

it('keeps striping while reordering', function (): void {
    $crawler = new Crawler(reorderingTableHtml(true));

    expect($crawler->filter('tr.sy-striped')->count())->toBe(1)
        ->and($crawler->filter('tr.sy-ta-row')->eq(1)->attr('class'))->toContain('sy-striped');
});

it('suppresses the selection chrome while reordering, swapping the banner for the indicator', function (): void {
    $selectable = fn (Table $table): Table => $table->bulkActions([BulkAction::make('archive')]);

    $off = new Crawler(reorderingTableHtml(configure: $selectable));
    $on = new Crawler(reorderingTableHtml(true, $selectable));

    expect($off->filter('.sy-ta-record-checkbox')->count())->toBe(3)
        ->and($off->filter('.sy-ta-page-checkbox')->count())->toBe(1)
        ->and($off->filter('.sy-ta-selection-indicator')->count())->toBe(1)
        ->and($off->filter('.sy-ta-reorder-indicator')->count())->toBe(0);

    $indicator = $on->filter('.sy-ta-reorder-indicator');

    // The count is what the suppressed banner and select-all link read, so the
    // query behind it is skipped for the whole of the mode rather than computed
    // for nobody — the dropped value is the only surface that shows it.
    expect($off->filter('div.sy.sy-ta')->attr('data-sylament--tables--table-all-selectable-count-value'))->toBe('3')
        ->and($on->filter('div.sy.sy-ta')->attr('data-sylament--tables--table-all-selectable-count-value'))->toBeNull()
        ->and($on->filter('.sy-ta-record-checkbox')->count())->toBe(0)
        ->and($on->filter('.sy-ta-page-checkbox')->count())->toBe(0)
        ->and($on->filter('.sy-ta-selection-indicator')->count())->toBe(0)
        ->and($indicator->count())->toBe(1)
        ->and($indicator->attr('role'))->toBe('status')
        ->and(mb_trim($indicator->text()))->toBe('Drag and drop the records into order.');
});

it('suppresses the record, header and toolbar actions while reordering', function (): void {
    $actions = fn (Table $table): Table => $table
        ->recordActions([Action::make('edit')])
        ->headerActions([Action::make('create')])
        ->toolbarActions([Action::make('export')]);

    $off = new Crawler(reorderingTableHtml(configure: $actions));
    $on = new Crawler(reorderingTableHtml(true, $actions));

    expect($off->filter('.sy-ta-actions')->count())->toBe(3)
        ->and($off->filter('.sy-ta-header-actions')->count())->toBe(1)
        ->and($off->filter('.sy-ta-header-toolbar-actions .sy-ac')->count())->toBe(2)
        ->and($on->filter('.sy-ta-actions')->count())->toBe(0)
        ->and($on->filter('.sy-ta-header-actions')->count())->toBe(0)
        ->and($on->filter('.sy-ta-header-toolbar-actions .sy-ac')->count())->toBe(1);
});

it('vetoes every cell link and unclickable header while reordering', function (): void {
    $clickable = fn (Table $table): Table => $table->recordUrl(fn (): string => '/songs/1');

    $off = new Crawler(reorderingTableHtml(configure: $clickable));
    $on = new Crawler(reorderingTableHtml(true, $clickable));

    expect($off->filter('td.sy-ta-cell a.sy-ta-col')->count())->toBe(3)
        ->and($off->filter('th.sy-ta-header-cell button')->count())->toBe(1)
        ->and($on->filter('td.sy-ta-cell a.sy-ta-col')->count())->toBe(0)
        ->and($on->filter('td.sy-ta-cell div.sy-ta-col')->count())->toBe(3)
        ->and($on->filter('th.sy-ta-header-cell button')->count())->toBe(0)
        ->and($on->filter('th.sy-ta-header-cell')->first()->attr('aria-sort'))->toBeNull();
});

it('suppresses the grouping selector, the group headers and the group checkboxes while reordering', function (): void {
    // The offered group and the one in force are deliberately different: a
    // default the selector does not list is the only grouping that resolves on
    // a host nothing has mounted, and it leaves the selector something to show.
    $grouped = fn (Table $table): Table => $table
        ->groups(['album'])
        ->defaultGroup('title')
        ->bulkActions([BulkAction::make('archive')]);

    $off = new Crawler(reorderingTableHtml(configure: $grouped));
    $on = new Crawler(reorderingTableHtml(true, $grouped));

    expect($off->filter('.sy-ta-grouping-settings')->count())->toBe(1)
        ->and($off->filter('tr.sy-ta-group-header-row')->count())->toBe(3)
        ->and($off->filter('input.sy-ta-group-checkbox')->count())->toBe(3)
        ->and($off->filter('tr[data-sy-group]')->count())->toBe(6)
        ->and($on->filter('.sy-ta-grouping-settings')->count())->toBe(0)
        ->and($on->filter('tr.sy-ta-group-header-row')->count())->toBe(0)
        ->and($on->filter('input.sy-ta-group-checkbox')->count())->toBe(0)
        ->and($on->filter('tr[data-sy-group]')->count())->toBe(0)
        ->and($on->filter('tr[data-sortable-item]')->count())->toBe(3);
});

it('suppresses every summary row while reordering', function (): void {
    $summarized = fn (Table $table): Table => $table->columns([TextColumn::make('title')->summarize(Count::make())]);

    $off = new Crawler(reorderingTableHtml(configure: $summarized));
    $on = new Crawler(reorderingTableHtml(true, $summarized));

    expect($off->filter('tr.sy-ta-summary-row')->count())->toBe(1)
        ->and($on->filter('tr.sy-ta-summary-row')->count())->toBe(0)
        ->and($on->filter('tr.sy-ta-summary-header-row')->count())->toBe(0)
        ->and($on->filter('tr[data-sortable-item]')->count())->toBe(3);
});
