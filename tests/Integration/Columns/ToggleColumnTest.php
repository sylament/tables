<?php

declare(strict_types=1);

namespace Sylament\Tables\Tests\Integration\Columns;

use Sylament\Tables\Columns\ToggleColumn;
use Sylament\Tables\Table;
use Sylament\Tables\Tests\Fixtures\Concerns\TableHost;
use Symfony\Component\DomCrawler\Crawler;

use function Sylament\Tables\Tests\renderToHtml;

/*
 * The shipped `ToggleColumn` partial through a real table render: forms' toggle
 * skin inside the cell's own `div.sy.sy-ta-toggle`, carrying the per-cell
 * controller the client repaints and commits through.
 */

function toggleCell(ToggleColumn $column, mixed $state): Crawler
{
    $host = new TableHost(fn (Table $table): Table => $table
        ->records(fn (): array => [['__key' => '7', 'isFeatured' => $state]])
        ->columns([$column]));

    return new Crawler(renderToHtml($host->getTable()))
        ->filter('td.sy-ta-cell > div.sy-ta-col > div.sy.sy-ta-toggle');
}

it('renders the toggle skin bound to the per-cell controller', function (): void {
    $cell = toggleCell(ToggleColumn::make('isFeatured'), true);
    $input = $cell->filter('label.sy-fo-toggle > input.sy-fo-toggle-input');

    expect($cell->attr('data-controller'))->toBe('sylament--tables--editable-cell')
        ->and($cell->attr('data-sylament--tables--editable-cell-column-value'))->toBe('isFeatured')
        ->and($cell->attr('data-sylament--tables--editable-cell-record-value'))->toBe('7')
        ->and($cell->attr('data-sylament--tables--editable-cell-state-value'))->toBe('1')
        ->and($input->attr('type'))->toBe('checkbox')
        ->and($input->attr('role'))->toBe('switch')
        ->and($input->attr('checked'))->not->toBeNull()
        ->and($input->attr('aria-label'))->toBe('Is featured')
        ->and($input->attr('aria-invalid'))->toBeNull()
        ->and($input->attr('data-action'))->toBe('change->sylament--tables--editable-cell#commit');
});

it('publishes an unset state as zero and leaves the control unchecked', function (): void {
    $cell = toggleCell(ToggleColumn::make('isFeatured'), null);

    expect($cell->attr('data-sylament--tables--editable-cell-state-value'))->toBe('0')
        ->and($cell->filter('input')->attr('checked'))->toBeNull();
});

it('disables the control for the row its closure refuses', function (): void {
    $cell = toggleCell(ToggleColumn::make('isFeatured')->disabled(), true);

    expect($cell->filter('input')->attr('disabled'))->not->toBeNull();
});

it('renders the thumb icons and the colour attributes the toggle carries', function (): void {
    $cell = toggleCell(
        ToggleColumn::make('isFeatured')->onColor('success')->onIcon('heroicons:check-circle')->offIcon('heroicons:x-circle'),
        true,
    );

    expect($cell->filter('span.sy-fo-toggle-icon-on svg')->count())->toBe(1)
        ->and($cell->filter('span.sy-fo-toggle-icon-off svg')->count())->toBe(1)
        ->and($cell->filter('span.sy-fo-toggle-track')->attr('class'))->toContain('sy-color-success');
});

it('marks the cell inline and aligned from the column', function (): void {
    $cell = toggleCell(ToggleColumn::make('isFeatured')->inline()->alignment('center'), true);

    expect($cell->attr('class'))->toContain('sy-inline')
        ->and($cell->attr('class'))->toContain('sy-align-center');
});
