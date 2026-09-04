<?php

declare(strict_types=1);

namespace Sylament\Tables\Tests\Integration\Columns;

use Sylament\Tables\Columns\ColorColumn;
use Sylament\Tables\Table;
use Sylament\Tables\Tests\Fixtures\Concerns\TableHost;
use Symfony\Component\DomCrawler\Crawler;

use function Sylament\Tables\Tests\renderToHtml;

/*
 * Drives the shipped `ColorColumn` partial through a real table render: the named swatch, the
 * copyable one carrying support's clipboard controller, and the value no CSS colour syntax
 * admits, which paints and names nothing.
 */

function colorCell(ColorColumn $column, mixed $state): Crawler
{
    $host = new TableHost(fn (Table $table): Table => $table
        ->records(fn (): array => [['color' => $state]])
        ->columns([$column]));

    return new Crawler(renderToHtml($host->getTable()))
        ->filter('td.sy-ta-cell > div.sy-ta-col > div.sy.sy-ta-color');
}

it('paints and names a plain swatch', function (): void {
    $swatch = colorCell(ColorColumn::make('color'), '#ff0000')->filter('span.sy-ta-color-item');

    expect($swatch->count())->toBe(1)
        ->and($swatch->attr('style'))->toBe('background-color: #ff0000')
        ->and($swatch->attr('role'))->toBe('img')
        ->and($swatch->attr('aria-label'))->toBe('#ff0000')
        ->and($swatch->attr('data-controller'))->toBeNull();
});

it('paints no background and names nothing for an unsanitizable colour', function (): void {
    $swatch = colorCell(ColorColumn::make('color'), 'red;position:fixed')->filter('span.sy-ta-color-item');

    expect($swatch->count())->toBe(1)
        ->and($swatch->attr('style'))->toBeNull()
        ->and($swatch->attr('role'))->toBeNull()
        ->and($swatch->attr('aria-label'))->toBeNull();
});

it('turns a copyable swatch into a named button carrying the clipboard controller', function (): void {
    $column = ColorColumn::make('color')->copyable()->copyMessage('Colour copied')->copyMessageDuration(500);
    $swatch = colorCell($column, '#00ff00')->filter('span.sy-ta-color-item');

    expect($swatch->attr('class'))->toContain('sy-copyable')
        ->and($swatch->attr('role'))->toBe('button')
        ->and($swatch->attr('aria-label'))->toBe('Copy #00ff00')
        ->and($swatch->attr('data-controller'))->toBe('sylament--support--clipboard')
        ->and($swatch->attr('data-sylament--support--clipboard-text-value'))->toBe('#00ff00')
        ->and($swatch->attr('data-sylament--support--clipboard-message-value'))->toBe('Colour copied')
        ->and($swatch->attr('data-sylament--support--clipboard-duration-value'))->toBe('500')
        ->and($swatch->attr('tabindex'))->toBeNull();
});

/*
 * The copyable arm is a focusable, operable control the moment the clipboard controller connects,
 * so it needs a name for every value the sanitizer refuses too — the swatch still copies the raw
 * state, and a colour-free verb is the only name left to give it.
 */
it('names a copyable swatch whose colour is unsanitizable without painting it', function (): void {
    $swatch = colorCell(ColorColumn::make('color')->copyable(), 'brand-blue')->filter('span.sy-ta-color-item');

    expect($swatch->attr('role'))->toBe('button')
        ->and($swatch->attr('aria-label'))->toBe('Copy colour')
        ->and($swatch->attr('style'))->toBeNull()
        ->and($swatch->attr('data-controller'))->toBe('sylament--support--clipboard')
        ->and($swatch->attr('data-sylament--support--clipboard-text-value'))->toBe('brand-blue');
});

it('binds the copy action to click and both activation keys', function (): void {
    $swatch = colorCell(ColorColumn::make('color')->copyable(), '#00ff00')->filter('span.sy-ta-color-item');
    $action = $swatch->attr('data-action') ?? '';

    expect($action)->toContain('click->sylament--support--clipboard#copy')
        ->and($action)->toContain('keydown.enter->sylament--support--clipboard#copy')
        ->and($action)->toContain('keydown.space->sylament--support--clipboard#copy');
});

it('renders one swatch per value and wraps a list', function (): void {
    $cell = colorCell(ColorColumn::make('color')->wrap(), ['#fff', '#000']);

    expect($cell->attr('class'))->toContain('sy-wrapped')
        ->and($cell->filter('span.sy-ta-color-item')->count())->toBe(2)
        ->and($cell->filter('span.sy-ta-color-item')->eq(1)->attr('aria-label'))->toBe('#000');
});

it('renders the placeholder paragraph and the empty tooltip for a blank state', function (): void {
    $cell = colorCell(ColorColumn::make('color')->placeholder('No colour')->emptyTooltip('Nothing picked'), null);

    expect($cell->filter('span.sy-ta-color-item')->count())->toBe(0)
        ->and($cell->filter('p.sy-ta-placeholder')->text())->toBe('No colour')
        ->and($cell->attr('title'))->toBe('Nothing picked');
});

it('titles a swatch with its per-item tooltip', function (): void {
    $column = ColorColumn::make('color')->tooltip(fn (mixed $state): string => 'Brand ' . $state);

    expect(colorCell($column, '#fff')->filter('span.sy-ta-color-item')->attr('title'))->toBe('Brand #fff');
});

it('links a swatch the column answers a state-based url for', function (): void {
    $column = ColorColumn::make('color')->url(fn (mixed $state): string => '/colours/' . mb_trim((string) $state, '#'), shouldOpenInNewTab: true);
    $link = colorCell($column, '#ff0000')->filter('a');

    expect($link->count())->toBe(1)
        ->and($link->attr('href'))->toBe('/colours/ff0000')
        ->and($link->attr('target'))->toBe('_blank')
        ->and($link->filter('span.sy-ta-color-item')->count())->toBe(1);
});

it('leaves a swatch unlinked when the column url does not read the state', function (): void {
    $host = new TableHost(fn (Table $table): Table => $table
        ->records(fn (): array => [['color' => '#ff0000']])
        ->columns([ColorColumn::make('color')->url('/colours')]));

    $cell = new Crawler(renderToHtml($host->getTable()))->filter('td.sy-ta-cell');

    expect($cell->filter('a div.sy-ta-color span.sy-ta-color-item')->count())->toBe(1)
        ->and($cell->filter('div.sy-ta-color a')->count())->toBe(0);
});

it('marks an inline column and carries the wrapper extra attributes', function (): void {
    $cell = colorCell(ColorColumn::make('color')->inline()->extraAttributes(['data-probe' => 'color']), '#fff');

    expect($cell->attr('class'))->toContain('sy-inline')
        ->and($cell->attr('data-probe'))->toBe('color');
});
