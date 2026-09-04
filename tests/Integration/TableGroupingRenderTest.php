<?php

declare(strict_types=1);

namespace Sylament\Tables\Tests\Integration;

use Closure;
use Sylament\Actions\Action;
use Sylament\Actions\BulkAction;
use Sylament\Tables\Columns\Summarizers\Sum;
use Sylament\Tables\Columns\TextColumn;
use Sylament\Tables\Enums\RecordActionsPosition;
use Sylament\Tables\Enums\RecordCheckboxPosition;
use Sylament\Tables\Grouping\Group;
use Sylament\Tables\Table;
use Sylament\Tables\Tests\Fixtures\Concerns\TableHost;
use Sylament\Tables\Tests\Fixtures\Doctrine\Entity\Song as SongEntity;
use Sylament\Tables\Tests\Fixtures\Doctrine\EntityManagerFactory;
use Sylament\Tables\Tests\Fixtures\Records\Artist;
use Sylament\Tables\Tests\Fixtures\Records\Song;
use Symfony\Component\DomCrawler\Crawler;

use function Sylament\Tables\Tests\renderToHtml;

/*
 * Grouping as the partial renders it: the group header row opened at each
 * boundary, the heading, description and collapse control it carries, the group
 * checkbox that ticks a whole group, the stripe restarting inside each group and
 * the two arms `selectGroupsOnly()` removes. The suppression while reordering is
 * asserted as an off/on pair in `TableReorderingTest`, where every other mode
 * gate of this partial is pinned.
 *
 * A record row is counted as `tbody tr[id*=".table.records."]`, never as
 * `tbody .sy-ta-row` — the group header row carries that class too, as vendor's
 * does.
 */

/**
 * A six-row grouped table — three songs on Radiohead, three on Björk, in group
 * order, because the records lane orders nothing itself. The host's own property
 * carries the grouping, which is where the selector writes it from the client.
 *
 * @param list<string> $selectedKeys
 */
function groupedTableHtml(?string $grouping = 'artist.name', ?Closure $configure = null, array $selectedKeys = []): string
{
    $radiohead = new Artist('Radiohead');
    $bjork = new Artist('Björk');

    $host = new TableHost(fn (Table $table): Table => ($configure ?? static fn (Table $table): Table => $table)(
        $table
            ->records(fn (): array => [
                new Song('Airbag', 'OK Computer', $radiohead, id: 1),
                new Song('Let Down', 'OK Computer', $radiohead, id: 2),
                new Song('Creep', 'Pablo Honey', $radiohead, id: 3),
                new Song('Hyperballad', 'Post', $bjork, id: 4),
                new Song('Army of Me', 'Post', $bjork, id: 5),
                new Song('Jóga', 'Homogenic', $bjork, id: 6),
            ])
            ->columns([TextColumn::make('title'), TextColumn::make('album')])
            ->groups(['artist.name', 'album']),
    ));

    $host->tableGrouping = $grouping;
    $host->selectedTableRecordKeys = $selectedKeys;

    return renderToHtml($host->getTable());
}

/**
 * The same table with one artist missing, so the null key's group renders.
 */
function unknownArtistTableHtml(): string
{
    $radiohead = new Artist('Radiohead');

    $host = new TableHost(fn (Table $table): Table => $table
        ->records(fn (): array => [
            new Song('Airbag', 'OK Computer', $radiohead, id: 1),
            new Song('Unattributed', 'Bootleg', id: 2),
        ])
        ->columns([TextColumn::make('title')])
        ->groups(['artist.name'])
        ->bulkActions([BulkAction::make('archive')]));

    $host->tableGrouping = 'artist.name';

    return renderToHtml($host->getTable());
}

it('renders no grouping chrome, and no toolbar at all, for a table declaring no groups', function (): void {
    $crawler = new Crawler(groupedTableHtml(grouping: null, configure: fn (Table $table): Table => $table->groups([])));

    expect($crawler->filter('.sy-ta-grouping-settings')->count())->toBe(0)
        ->and($crawler->filter('.sy-ta-header-toolbar')->count())->toBe(0);
});

it('opens the toolbar for a table whose only member is the grouping selector', function (): void {
    $crawler = new Crawler(groupedTableHtml());

    // The toolbar and its start group widen for the selector; the header above
    // them does not, so a grouping-only table opens no header at all.
    expect($crawler->filter('.sy-ta-header-toolbar')->count())->toBe(1)
        ->and($crawler->filter('.sy-ta-header-toolbar-actions')->count())->toBe(1)
        ->and($crawler->filter('.sy-ta-header-toolbar-actions > .sy-ta-grouping-settings')->count())->toBe(1)
        ->and($crawler->filter('.sy-ta-header')->count())->toBe(0);
});

it('offers one option per registered group beside a blank one', function (): void {
    $selects = new Crawler(groupedTableHtml(grouping: null))
        ->filter('.sy-ta-grouping-settings [data-sylament--tables--table-target="groupColumn"]');

    expect($selects->count())->toBe(2)
        ->and($selects->first()->attr('data-action'))->toBe('change->sylament--tables--table#group')
        ->and($selects->first()->attr('aria-label'))->toBe('Group by')
        ->and($selects->first()->filter('option')->each(fn (Crawler $option): ?string => $option->attr('value')))
        ->toBe(['', 'artist.name', 'album'])
        ->and($selects->first()->filter('option')->each(fn (Crawler $option): string => $option->text()))
        ->toBe(['-', 'Artist', 'Album'])
        ->and($selects->first()->filter('option[selected]')->attr('value'))->toBe('');
});

it('marks the option of the group in force', function (): void {
    $crawler = new Crawler(groupedTableHtml('album'));
    $selected = $crawler->filter('[data-sylament--tables--table-target="groupColumn"]')->first()->filter('option[selected]');

    expect($selected->count())->toBe(1)
        ->and($selected->attr('value'))->toBe('album');
});

it('reveals the direction select once a group is in force and drops it on request', function (): void {
    $ungrouped = new Crawler(groupedTableHtml(grouping: null));
    $grouped = new Crawler(groupedTableHtml('album:desc'));

    $ungroupedField = $ungrouped->filter('[data-sylament--tables--table-target="groupDirectionField"]')->first();
    $groupedField = $grouped->filter('[data-sylament--tables--table-target="groupDirectionField"]')->first();
    $directionSelect = $groupedField->filter('[data-sylament--tables--table-target="groupDirection"]');

    expect($ungroupedField->attr('hidden'))->not->toBeNull()
        ->and($groupedField->attr('hidden'))->toBeNull()
        ->and($directionSelect->attr('data-action'))->toBe('change->sylament--tables--table#group')
        ->and($directionSelect->filter('option')->each(fn (Crawler $option): string => $option->text()))
        ->toBe(['Ascending', 'Descending'])
        ->and($directionSelect->filter('option[selected]')->attr('value'))->toBe('desc');

    $hidden = new Crawler(groupedTableHtml('album', fn (Table $table): Table => $table->groupingDirectionSettingHidden()));

    expect($hidden->filter('[data-sylament--tables--table-target="groupDirectionField"]')->count())->toBe(0)
        ->and($hidden->filter('[data-sylament--tables--table-target="groupColumn"]')->count())->toBe(2);
});

it('hides the whole selector under groupingSettingsHidden() while the grouping still applies', function (): void {
    $crawler = new Crawler(groupedTableHtml(configure: fn (Table $table): Table => $table->groupingSettingsHidden()));

    expect($crawler->filter('.sy-ta-grouping-settings')->count())->toBe(0)
        ->and($crawler->filter('.sy-ta-header-toolbar')->count())->toBe(0)
        ->and($crawler->filter('tr.sy-ta-group-header-row')->count())->toBe(2);
});

it('renders the trigger in a dropdown and keeps the inline copy off the desktop on request', function (): void {
    $crawler = new Crawler(groupedTableHtml());
    $settings = $crawler->filter('.sy-ta-grouping-settings');
    $trigger = $settings->filter('.sy-dropdown .sy-dropdown-trigger button.sy-icon-btn');

    expect($settings->attr('class'))->not->toContain('sy-ta-grouping-settings-dropdown-on-desktop')
        ->and($settings->filter('.sy-ta-grouping-settings-fields')->count())->toBe(2)
        ->and($settings->filter('.sy-dropdown')->attr('data-sylament--support--dropdown-teleport-value'))->toBe('true')
        ->and($settings->filter('.sy-dropdown .sy-dropdown-panel')->attr('style'))->toContain('--sy-dropdown-width: 20rem')
        ->and($trigger->count())->toBe(1)
        ->and($trigger->attr('aria-label'))->toBe('Group')
        ->and($trigger->filter('svg.sy-icon')->attr('viewBox'))->toBe('0 0 20 20');

    $onDesktop = new Crawler(groupedTableHtml(configure: fn (Table $table): Table => $table
        ->groupingSettingsInDropdownOnDesktop()))->filter('.sy-ta-grouping-settings');

    expect($onDesktop->attr('class'))->toContain('sy-ta-grouping-settings-dropdown-on-desktop')
        ->and($onDesktop->filter('.sy-ta-grouping-settings-fields')->count())->toBe(1);
});

it('shapes the dropdown copy with visible labels and the inline copy with a wrapper prefix', function (): void {
    $settings = new Crawler(groupedTableHtml('album'))->filter('.sy-ta-grouping-settings');
    $dropdown = $settings->filter('.sy-dropdown .sy-ta-grouping-settings-fields');
    $inline = $settings->filter('.sy-ta-grouping-settings > .sy-ta-grouping-settings-fields');

    // Vendor writes the two copies as two markups: labels above the selects in
    // the dropdown, and in the toolbar the group label as the wrapper's prefix
    // with the direction named for screen readers only.
    expect($dropdown->filter('.sy-ta-grouping-settings-field-label')->each(fn (Crawler $label): string => $label->text()))
        ->toBe(['Group by', 'Group direction'])
        ->and($dropdown->filter('.sy-input-wrp-prefix')->count())->toBe(0)
        ->and($inline->filter('.sy-ta-grouping-settings-field-label')->count())->toBe(0)
        ->and($inline->filter('.sy-input-wrp-prefix .sy-input-wrp-label')->text())->toBe('Group by')
        ->and($inline->filter('[data-sylament--tables--table-target="groupDirectionField"] > span')->attr('class'))->toBe('sy-sr-only')
        ->and($inline->filter('[data-sylament--tables--table-target="groupDirectionField"] > span')->text())->toBe('Group direction')
        ->and($inline->filter('[data-sylament--tables--table-target="groupDirection"]')->count())->toBe(1);
});

it('opens a group header row at each boundary and nowhere else', function (): void {
    $crawler = new Crawler(groupedTableHtml());
    $rows = $crawler->filter('tbody > tr');

    expect($crawler->filter('tr.sy-ta-group-header-row')->count())->toBe(2)
        ->and($crawler->filter('tbody tr[id*=".table.records."]')->count())->toBe(6)
        ->and($rows->eq(0)->attr('class'))->toContain('sy-ta-group-header-row')
        ->and($rows->eq(0)->filter('.sy-ta-group-heading')->text())->toBe('Artist: Radiohead')
        ->and($rows->eq(4)->attr('class'))->toContain('sy-ta-group-header-row')
        ->and($rows->eq(4)->filter('.sy-ta-group-heading')->text())->toBe('Artist: Björk');
});

it('renders no group header rows for a table with no grouping resolved', function (): void {
    $crawler = new Crawler(groupedTableHtml(grouping: null));

    expect($crawler->filter('tr.sy-ta-group-header-row')->count())->toBe(0)
        ->and($crawler->filter('tbody tr[id*=".table.records."]')->count())->toBe(6)
        ->and($crawler->filter('tr[data-sy-group]')->count())->toBe(0);
});

it('carries the group title on the header row and on every record row', function (): void {
    $crawler = new Crawler(groupedTableHtml());
    $header = $crawler->filter('tr.sy-ta-group-header-row')->first();

    expect($header->attr('data-sy-group'))->toBe('Radiohead')
        ->and($header->attr('id'))->toEndWith('.table.groups.Radiohead')
        ->and($crawler->filter('tr[data-sy-group="Radiohead"]')->count())->toBe(4)
        ->and($crawler->filter('tr[data-sy-group="Björk"]')->count())->toBe(4);
});

it('keys the blank group with an empty title on both row kinds', function (): void {
    $crawler = new Crawler(unknownArtistTableHtml());
    $header = $crawler->filter('tr.sy-ta-group-header-row')->last();

    expect($crawler->filter('tr.sy-ta-group-header-row')->count())->toBe(2)
        ->and($header->attr('data-sy-group'))->toBe('')
        ->and($header->attr('id'))->toEndWith('.table.groups.')
        ->and($header->filter('.sy-ta-group-heading')->text())->toBe('Artist:')
        ->and($crawler->filter('tr[data-sy-group=""]')->count())->toBe(2);
});

it('spans the group header row across every cell the head emits', function (
    ?RecordActionsPosition $actionsPosition,
    ?RecordCheckboxPosition $checkboxPosition,
): void {
    $crawler = new Crawler(groupedTableHtml(configure: function (Table $table) use ($actionsPosition, $checkboxPosition): Table {
        if ($actionsPosition instanceof RecordActionsPosition) {
            $table->recordActions([Action::make('edit')], position: $actionsPosition);
        }

        return $checkboxPosition instanceof RecordCheckboxPosition
            ? $table->bulkActions([BulkAction::make('archive')])->recordCheckboxPosition($checkboxPosition)
            : $table->selectable(false);
    }));

    $header = $crawler->filter('tr.sy-ta-group-header-row')->first();
    $colspan = (int) $header->filter('td.sy-ta-group-header-cell')->attr('colspan');

    expect($colspan + $header->filter('td')->count() - 1)->toBe($crawler->filter('thead > tr > th')->count());

    if (!$checkboxPosition instanceof RecordCheckboxPosition) {
        return;
    }

    // Width is not placement: the group checkbox has to land in the column the
    // selection header names, which the colspan is what decides.
    $column = 0;

    foreach ($header->filter('td')->each(fn (Crawler $cell): array => [(string) $cell->attr('class'), (int) ($cell->attr('colspan') ?? 1)]) as [$class, $span]) {
        if (str_contains($class, 'sy-ta-group-selection-cell')) {
            break;
        }

        $column += $span;
    }

    $headerCells = $crawler->filter('thead > tr > th')->each(fn (Crawler $cell): string => (string) $cell->attr('class'));

    expect($column)->toBe(array_search('sy-ta-selection-cell', $headerCells, true));
})->with([
    'no actions, no selection' => [null, null],
    'no actions, leading checkbox' => [null, RecordCheckboxPosition::BeforeCells],
    'no actions, trailing checkbox' => [null, RecordCheckboxPosition::AfterCells],
    'leading actions, no selection' => [RecordActionsPosition::BeforeCells, null],
    'leading actions, leading checkbox' => [RecordActionsPosition::BeforeCells, RecordCheckboxPosition::BeforeCells],
    'leading actions, trailing checkbox' => [RecordActionsPosition::BeforeCells, RecordCheckboxPosition::AfterCells],
    'actions before the columns, leading checkbox' => [RecordActionsPosition::BeforeColumns, RecordCheckboxPosition::BeforeCells],
    'actions after the columns, leading checkbox' => [RecordActionsPosition::AfterColumns, RecordCheckboxPosition::BeforeCells],
    'trailing actions, leading checkbox' => [RecordActionsPosition::AfterCells, RecordCheckboxPosition::BeforeCells],
    'trailing actions, trailing checkbox' => [RecordActionsPosition::AfterCells, RecordCheckboxPosition::AfterCells],
]);

it('prefixes the heading with the group label until the group refuses it', function (): void {
    $bare = new Crawler(groupedTableHtml(configure: fn (Table $table): Table => $table
        ->groups([Group::make('artist.name')->titlePrefixedWithLabel(false)])));

    expect(new Crawler(groupedTableHtml())->filter('.sy-ta-group-heading')->first()->text())->toBe('Artist: Radiohead')
        ->and($bare->filter('.sy-ta-group-heading')->first()->text())->toBe('Radiohead');
});

it('renders a group description under the heading only when one resolves', function (): void {
    $described = new Crawler(groupedTableHtml(configure: fn (Table $table): Table => $table
        ->groups([Group::make('artist.name')->getDescriptionFromRecordUsing(fn (Song $record): string => $record->album)])));

    expect($described->filter('.sy-ta-group-header p.sy-ta-group-description')->count())->toBe(2)
        ->and($described->filter('.sy-ta-group-description')->first()->text())->toBe('OK Computer')
        ->and(new Crawler(groupedTableHtml())->filter('.sy-ta-group-description')->count())->toBe(0);
});

it('renders a collapse control only for a collapsible group', function (): void {
    $collapsible = new Crawler(groupedTableHtml(configure: fn (Table $table): Table => $table
        ->groups([Group::make('artist.name')->collapsible()])));

    $header = $collapsible->filter('.sy-ta-group-header')->first();
    $button = $header->filter('button.sy-icon-btn.sy-size-sm');

    expect($header->attr('class'))->toContain('sy-collapsible')
        ->and($header->attr('data-action'))->toBe('click->sylament--tables--table#toggleGroup')
        ->and($button->count())->toBe(1)
        ->and($button->attr('type'))->toBe('button')
        ->and($button->attr('aria-expanded'))->toBe('true')
        ->and($button->attr('aria-label'))->toBe('Artist: Radiohead')
        ->and($button->attr('data-action'))->toContain('click->sylament--tables--table#toggleGroup')
        ->and($button->filter('svg.sy-icon')->count())->toBe(1);

    $plain = new Crawler(groupedTableHtml())->filter('.sy-ta-group-header')->first();

    expect($plain->attr('class'))->not->toContain('sy-collapsible')
        ->and($plain->attr('data-action'))->toBeNull()
        ->and($plain->filter('button')->count())->toBe(0);
});

it('paints every group collapsed on the first render under collapsedGroupsByDefault()', function (): void {
    $crawler = new Crawler(groupedTableHtml(configure: fn (Table $table): Table => $table
        ->groups([Group::make('artist.name')->collapsible()])
        ->collapsedGroupsByDefault()));

    expect($crawler->filter('.sy-ta-group-header.sy-collapsed')->count())->toBe(2)
        ->and($crawler->filter('tr.sy-ta-group-header-row.sy-collapsed')->count())->toBe(0)
        ->and($crawler->filter('tbody tr[id*=".table.records."].sy-collapsed')->count())->toBe(6)
        ->and($crawler->filter('.sy-ta-group-header button')->first()->attr('aria-expanded'))->toBe('false')
        ->and(new Crawler(groupedTableHtml())->filter('.sy-collapsed')->count())->toBe(0);
});

it('leaves a non-collapsible group expanded under collapsedGroupsByDefault()', function (): void {
    $crawler = new Crawler(groupedTableHtml(configure: fn (Table $table): Table => $table->collapsedGroupsByDefault()));

    expect($crawler->filter('.sy-collapsed')->count())->toBe(0);
});

it('restarts the record striping at each group boundary', function (): void {
    $crawler = new Crawler(groupedTableHtml(configure: fn (Table $table): Table => $table->striped()));
    $striped = $crawler->filter('tbody tr[id*=".table.records."]')
        ->each(fn (Crawler $row): bool => str_contains($row->attr('class') ?? '', 'sy-striped'));

    expect($striped)->toBe([false, true, false, false, true, false]);
});

it('ticks a whole group through one checkbox carrying its keys', function (): void {
    $crawler = new Crawler(groupedTableHtml(configure: fn (Table $table): Table => $table
        ->bulkActions([BulkAction::make('archive')])));

    $checkboxes = $crawler->filter('tr.sy-ta-group-header-row > td.sy-ta-cell.sy-ta-group-selection-cell > input.sy-checkbox.sy-ta-group-checkbox');

    expect($checkboxes->count())->toBe(2)
        ->and($checkboxes->first()->attr('type'))->toBe('checkbox')
        ->and($checkboxes->first()->attr('data-sylament--tables--table-target'))->toBe('groupCheckbox')
        ->and($checkboxes->first()->attr('data-action'))->toBe('change->sylament--tables--table#toggleGroupRecords')
        ->and(json_decode($checkboxes->first()->attr('data-sy-group-keys') ?? '', true))->toBe(['1', '2', '3'])
        ->and(json_decode($checkboxes->last()->attr('data-sy-group-keys') ?? '', true))->toBe(['4', '5', '6'])
        ->and($checkboxes->first()->attr('aria-label'))->toBe('Select/deselect every record in group Radiohead for bulk actions.');
});

it('gives each group checkbox an id of its own', function (): void {
    $crawler = new Crawler(groupedTableHtml(configure: fn (Table $table): Table => $table
        ->bulkActions([BulkAction::make('archive')])));

    $ids = $crawler->filter('input.sy-ta-group-checkbox')->each(fn (Crawler $box): ?string => $box->attr('id'));

    expect($ids)->toHaveCount(2)
        ->and($ids[0])->toEndWith('.table.groups.Radiohead.checkbox')
        ->and($ids[1])->toEndWith('.table.groups.Bj%C3%B6rk.checkbox')
        ->and($ids[0])->not->toBe($ids[1]);
});

it('paints a fully selected group checked and a partly selected one unchecked', function (): void {
    $crawler = new Crawler(groupedTableHtml(
        configure: fn (Table $table): Table => $table->bulkActions([BulkAction::make('archive')]),
        selectedKeys: ['1', '2', '3', '4'],
    ));

    $checkboxes = $crawler->filter('input.sy-ta-group-checkbox');

    expect($checkboxes->first()->attr('checked'))->not->toBeNull()
        ->and($checkboxes->last()->attr('checked'))->toBeNull();
});

it('paints a group with no selectable record unchecked', function (): void {
    $crawler = new Crawler(groupedTableHtml(
        configure: fn (Table $table): Table => $table
            ->bulkActions([BulkAction::make('archive')])
            ->checkIfRecordIsSelectableUsing(fn (Song $record): bool => $record->artist?->name === 'Radiohead'),
        selectedKeys: ['1', '2', '3'],
    ));

    $checkboxes = $crawler->filter('input.sy-ta-group-checkbox');

    expect($checkboxes->last()->attr('data-sy-group-keys'))->toBe('[]')
        ->and($checkboxes->last()->attr('checked'))->toBeNull()
        ->and($checkboxes->first()->attr('checked'))->not->toBeNull();
});

it('renders no group checkbox for a table with no bulk actions', function (): void {
    $crawler = new Crawler(groupedTableHtml());

    expect($crawler->filter('input.sy-ta-group-checkbox')->count())->toBe(0)
        ->and($crawler->filter('td.sy-ta-group-selection-cell')->count())->toBe(0)
        ->and($crawler->filter('tr.sy-ta-group-header-row')->count())->toBe(2);
});

it('opens no group header row and no selection chrome under groupsOnly()', function (): void {
    $crawler = new Crawler(groupedTableHtml(configure: fn (Table $table): Table => $table
        ->bulkActions([BulkAction::make('archive')])
        ->groupsOnly()));

    // The heading has moved onto the summary band's rows, which this table has
    // none of — nothing summarizes; what is pinned here is that the two gates
    // hold — no header row of ours, and no checkbox of any kind.
    expect($crawler->filter('tr.sy-ta-group-header-row')->count())->toBe(0)
        ->and($crawler->filter('input.sy-ta-group-checkbox')->count())->toBe(0)
        ->and($crawler->filter('input.sy-ta-record-checkbox')->count())->toBe(0)
        ->and($crawler->filter('thead th.sy-ta-selection-cell')->count())->toBe(0)
        ->and($crawler->filter('.sy-ta-selection-indicator')->count())->toBe(0);
});

it('drops the page checkbox but keeps its cell under selectGroupsOnly()', function (): void {
    $crawler = new Crawler(groupedTableHtml(configure: fn (Table $table): Table => $table
        ->bulkActions([BulkAction::make('archive')])
        ->selectGroupsOnly()));

    expect($crawler->filter('thead th.sy-ta-selection-cell')->count())->toBe(1)
        ->and($crawler->filter('input.sy-ta-page-checkbox')->count())->toBe(0)
        ->and($crawler->filter('tbody input.sy-ta-record-checkbox')->count())->toBe(6)
        ->and($crawler->filter('input.sy-ta-group-checkbox')->count())->toBe(2);
});

it('drops the select-all link but keeps the banner under selectGroupsOnly()', function (): void {
    $crawler = new Crawler(groupedTableHtml(configure: fn (Table $table): Table => $table
        ->toolbarActions([BulkAction::make('archive')])
        ->selectGroupsOnly()));

    $banner = $crawler->filter('div.sy-ta-selection-indicator');

    expect($banner->count())->toBe(1)
        ->and($banner->filter('[data-sylament--tables--table-target="selectAll"]')->count())->toBe(0)
        ->and($banner->filter('[data-sylament--tables--table-target="deselectAll"]')->count())->toBe(1);
});

it('keeps the controller and its values with the page checkbox gone', function (): void {
    $root = new Crawler(groupedTableHtml(configure: fn (Table $table): Table => $table
        ->bulkActions([BulkAction::make('archive')])
        ->selectGroupsOnly()))->filter('div.sy.sy-ta');

    expect($root->attr('data-controller'))->toBe('sylament--tables--table')
        ->and($root->attr('data-sylament--tables--table-can-track-value'))->toBe('true')
        ->and($root->attr('data-sylament--tables--table-page-only-value'))->toBe('true')
        ->and($root->attr('data-sylament--tables--table-all-selectable-count-value'))->toBe('6')
        ->and($root->attr('data-sylament--tables--table-groups-collapsed-by-default-value'))->toBe('false')
        ->and($root->attr('data-sylament--tables--table-count-one-value'))->toBe('1 record selected');
});

it('percent-encodes a group key carrying whitespace into the ids it mints', function (): void {
    $crawler = new Crawler(groupedTableHtml(
        grouping: 'album',
        configure: fn (Table $table): Table => $table->bulkActions([BulkAction::make('archive')]),
    ));

    $header = $crawler->filter('tr.sy-ta-group-header-row')->first();

    // An id may not carry ASCII whitespace, and the key is the user's string —
    // `data-sy-group` keeps it verbatim, because it is the collapse key the
    // header row and its records share.
    expect($header->attr('id'))->toEndWith('.table.groups.OK%20Computer')
        ->and($header->attr('id'))->not->toContain(' ')
        ->and($header->filter('input.sy-ta-group-checkbox')->attr('id'))->toEndWith('.table.groups.OK%20Computer.checkbox')
        ->and($header->attr('data-sy-group'))->toBe('OK Computer')
        ->and($crawler->filter('tbody tr[id*=".table.records."]')->first()->attr('data-sy-group'))->toBe('OK Computer');
});

it('hides the direction field while a non-selectable default group is in force', function (): void {
    $crawler = new Crawler(groupedTableHtml(
        grouping: null,
        configure: fn (Table $table): Table => $table->defaultGroup('title'),
    ));

    $fields = $crawler->filter('.sy-ta-grouping-settings-fields')->first();

    // The selects speak the client's own selection rather than the resolved
    // group: a default nothing selected leaves the group select blank, and a
    // direction select beside a blank group select would send nothing.
    expect($crawler->filter('tr.sy-ta-group-header-row')->count())->toBe(6)
        ->and($fields->filter('[data-sylament--tables--table-target="groupColumn"] option[selected]')->attr('value'))->toBe('')
        ->and($fields->filter('[data-sylament--tables--table-target="groupDirectionField"]')->attr('hidden'))->not->toBeNull();
});

/**
 * A grouped, summarized table on the query lane — three moods over five songs,
 * with the plays summed — because a summary reads its figure from a query and
 * the records lane above has none.
 */
function groupedSummaryTableHtml(?Closure $configure = null, bool $isSummarized = true): string
{
    $entityManager = EntityManagerFactory::create();

    EntityManagerFactory::seed(
        $entityManager,
        new SongEntity(id: 1, title: 'Airbag', plays: 10, mood: 'calm'),
        new SongEntity(id: 2, title: 'Airport', plays: 20, mood: 'calm'),
        new SongEntity(id: 3, title: 'Jóga', plays: 30, mood: 'bright'),
        new SongEntity(id: 4, title: 'Hyperballad', plays: 40, mood: 'bright'),
        new SongEntity(id: 5, title: 'Bachelorette', plays: 50, mood: 'dark'),
    );

    $plays = TextColumn::make('plays');

    $host = new TableHost(fn (Table $table): Table => ($configure ?? static fn (Table $table): Table => $table)(
        $table
            ->query($entityManager->createQueryBuilder()->select('song')->from(SongEntity::class, 'song')->orderBy('song.id', 'ASC'))
            ->columns([TextColumn::make('mood'), $isSummarized ? $plays->summarize(Sum::make()) : $plays])
            ->groups(['mood'])
            ->groupsOnly(),
    ));

    $host->tableGrouping = 'mood';

    return renderToHtml($host->getTable());
}

it('renders one summary row per group and no record rows under groupsOnly()', function (): void {
    $crawler = new Crawler(groupedSummaryTableHtml());

    $rows = $crawler->filter('tr.sy-ta-summary-row');

    expect($crawler->filter('tbody tr[id*=".table.records."]')->count())->toBe(0)
        ->and($crawler->filter('tr.sy-ta-group-header-row')->count())->toBe(0)
        ->and($rows->count())->toBe(4)
        ->and($rows->each(fn (Crawler $row): string => $row->filter('th[scope="row"]')->text()))
        // The bare title, not `<title> summary`: under groupsOnly() the group
        // heading has moved onto this row.
        ->toBe(['calm', 'bright', 'dark', 'Summary']);
});

it('drops the grouped column from the summary columns under groupsOnly()', function (): void {
    $row = new Crawler(groupedSummaryTableHtml())->filter('tr.sy-ta-summary-row')->first();

    expect($row->filter('th, td')->count())->toBe(2)
        ->and($row->filter('th[scope="row"]')->text())->toBe('calm')
        ->and($row->filter('td')->text())->toContain('30');
});

it('renders an empty body when groupsOnly() has nothing to summarize', function (): void {
    // Parity: the per-group rows are gated on a summary exactly as vendor's are,
    // so a groups-only table with no summarizer renders a table and no rows.
    $crawler = new Crawler(groupedSummaryTableHtml(isSummarized: false));

    expect($crawler->filter('table.sy-ta-table')->count())->toBe(1)
        ->and($crawler->filter('tbody tr')->count())->toBe(0);
});

it('paginates nothing under groupsOnly()', function (): void {
    $groupsOnly = new Crawler(groupedSummaryTableHtml(fn (Table $table): Table => $table->paginated([2])));

    // The twin is the same table with the mode off: `isPaginated()` is what drops
    // the footer, so the pages are still there for a grouped table that keeps its
    // record rows.
    $paginated = new Crawler(groupedSummaryTableHtml(fn (Table $table): Table => $table->paginated([2])->groupsOnly(false)));

    expect($groupsOnly->filter('nav.sy-pagination')->count())->toBe(0)
        ->and($paginated->filter('nav.sy-pagination')->count())->toBe(1);
});

it('is inert under groupsOnly() while no grouping is resolved', function (): void {
    $crawler = new Crawler(groupedTableHtml(grouping: null, configure: fn (Table $table): Table => $table
        ->bulkActions([BulkAction::make('archive')])
        ->groupsOnly()));

    expect($crawler->filter('tbody tr[id*=".table.records."]')->count())->toBe(6)
        ->and($crawler->filter('tr.sy-ta-group-header-row')->count())->toBe(0)
        ->and($crawler->filter('input.sy-ta-record-checkbox')->count())->toBe(6);
});
