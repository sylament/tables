<?php

declare(strict_types=1);

namespace Sylament\Tables\Tests\Integration\Columns;

use Sylament\Support\Contracts\HasColor;
use Sylament\Support\Contracts\HasIcon;
use Sylament\Support\Contracts\HasLabel;
use Sylament\Tables\Columns\IconColumn;
use Sylament\Tables\Table;
use Sylament\Tables\Tests\Fixtures\Concerns\TableHost;
use Symfony\Component\DomCrawler\Crawler;
use Twig\Markup;

use function Sylament\Tables\Tests\renderToHtml;

/*
 * Drives the shipped `IconColumn` partial through a real table render: the boolean pair over
 * the fixture icon set, a state carrying the icon and colour contracts, the list shapes, the
 * blank-state placeholder and the screen-reader text alternative every icon is named by.
 */

enum IconCellState: string implements HasColor, HasIcon, HasLabel
{
    case Approved = 'approved';

    public function getColor(): string
    {
        return 'warning';
    }

    public function getIcon(): string
    {
        return 'heroicons:check';
    }

    public function getLabel(): string
    {
        return 'Approved by an editor';
    }
}

function iconCell(IconColumn $column, mixed $state): Crawler
{
    $host = new TableHost(fn (Table $table): Table => $table
        ->records(fn (): array => [['flag' => $state]])
        ->columns([$column]));

    return new Crawler(renderToHtml($host->getTable()))
        ->filter('td.sy-ta-cell > div.sy-ta-col > div.sy.sy-ta-icon');
}

it('renders the check glyph and the success palette for a true state', function (): void {
    $cell = iconCell(IconColumn::make('flag')->boolean(), true);
    $icon = $cell->filter('svg.sy-icon');

    expect($icon->count())->toBe(1)
        ->and($icon->attr('class'))->toContain('sy-size-lg')
        ->and($icon->attr('class'))->toContain('sy-color-success')
        ->and($icon->attr('aria-hidden'))->toBe('true')
        ->and($cell->filter('span.sy-ta-sr-only')->text())->toBe('Yes');
});

it('renders the x glyph and the danger palette for a false state', function (): void {
    $cell = iconCell(IconColumn::make('flag')->boolean(), false);

    expect($cell->filter('svg.sy-icon')->attr('class'))->toContain('sy-color-danger')
        ->and($cell->filter('span.sy-ta-sr-only')->text())->toBe('No');
});

it('renders nothing but the root for a null state on a boolean column', function (): void {
    $cell = iconCell(IconColumn::make('flag')->boolean(), null);

    expect($cell->count())->toBe(1)
        ->and($cell->filter('svg')->count())->toBe(0)
        ->and($cell->filter('p.sy-ta-placeholder')->count())->toBe(0);
});

it('renders the placeholder paragraph and the empty tooltip for a blank state', function (): void {
    $cell = iconCell(IconColumn::make('flag')->placeholder('Not set')->emptyTooltip('Nothing recorded'), null);

    expect($cell->filter('p.sy-ta-placeholder')->text())->toBe('Not set')
        ->and($cell->attr('title'))->toBe('Nothing recorded');
});

it('takes the icon, colour and name off a state carrying the contracts', function (): void {
    $cell = iconCell(IconColumn::make('flag'), IconCellState::Approved);

    expect($cell->filter('svg.sy-icon')->attr('class'))->toContain('sy-color-warning')
        ->and($cell->filter('span.sy-ta-sr-only')->text())->toBe('Approved by an editor');
});

it('renders one icon per value and marks a line-break list', function (): void {
    $column = IconColumn::make('flag')->icon('heroicons:check')->listWithLineBreaks()->wrap();
    $cell = iconCell($column, ['first', 'second']);

    expect($cell->attr('class'))->toContain('sy-ta-icon-has-line-breaks')
        ->and($cell->attr('class'))->toContain('sy-wrapped')
        ->and($cell->filter('svg.sy-icon')->count())->toBe(2)
        ->and($cell->filter('span.sy-ta-sr-only')->eq(1)->text())->toBe('second');
});

it('drops an item whose icon resolves blank, its text alternative with it', function (): void {
    $column = IconColumn::make('flag')->icons(['heroicons:check' => 'first']);
    $cell = iconCell($column, ['first', 'second']);

    expect($cell->filter('svg.sy-icon')->count())->toBe(1)
        ->and($cell->filter('span.sy-ta-sr-only')->count())->toBe(1)
        ->and($cell->filter('span.sy-ta-sr-only')->text())->toBe('first');
});

it('wraps each icon in a link when the url closure reads the state', function (): void {
    $column = IconColumn::make('flag')
        ->icon('heroicons:check')
        ->url(fn (mixed $state): string => 'https://example.com/' . $state, shouldOpenInNewTab: true);
    $cell = iconCell($column, ['a', 'b']);

    expect($cell->filter('a')->count())->toBe(2)
        ->and($cell->filter('a')->attr('href'))->toBe('https://example.com/a')
        ->and($cell->filter('a')->attr('target'))->toBe('_blank')
        ->and($cell->filter('a > svg.sy-icon')->count())->toBe(2);
});

it('names the icon from the tooltip and titles it, flattening markup', function (): void {
    $column = IconColumn::make('flag')
        ->icon('heroicons:check')
        ->tooltip(new Markup('<b>Bold</b> tip', 'UTF-8'));
    $cell = iconCell($column, 'x');

    expect($cell->filter('svg.sy-icon')->attr('title'))->toBe('Bold tip')
        ->and($cell->filter('svg.sy-icon')->attr('aria-hidden'))->toBe('true')
        ->and($cell->filter('span.sy-ta-sr-only')->text())->toBe('Bold tip');
});

it('marks an inline column and carries the wrapper extra attributes', function (): void {
    $column = IconColumn::make('flag')->icon('heroicons:check')->inline()->extraAttributes(['data-probe' => 'icon']);
    $cell = iconCell($column, 'x');

    expect($cell->attr('class'))->toContain('sy-inline')
        ->and($cell->attr('data-probe'))->toBe('icon');
});

it('sizes an icon from the size setter', function (): void {
    $cell = iconCell(IconColumn::make('flag')->icon('heroicons:check')->size('sm'), 'x');

    expect($cell->filter('svg.sy-icon')->attr('class'))->toContain('sy-size-sm');
});
