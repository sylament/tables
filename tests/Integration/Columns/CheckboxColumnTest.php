<?php

declare(strict_types=1);

namespace Sylament\Tables\Tests\Integration\Columns;

use Sylament\Tables\Columns\CheckboxColumn;
use Sylament\Tables\Table;
use Sylament\Tables\Tests\Fixtures\Concerns\TableHost;
use Symfony\Component\DomCrawler\Crawler;

use function Sylament\Tables\Tests\renderToHtml;

/*
 * The shipped `CheckboxColumn` partial through a real table render: support's
 * `sy-checkbox` skin — the one record selection already uses — inside the cell's
 * own `div.sy.sy-ta-checkbox`.
 */

function checkboxCell(CheckboxColumn $column, mixed $state): Crawler
{
    $host = new TableHost(fn (Table $table): Table => $table
        ->records(fn (): array => [['__key' => '7', 'isExplicit' => $state]])
        ->columns([$column]));

    return new Crawler(renderToHtml($host->getTable()))
        ->filter('td.sy-ta-cell > div.sy-ta-col > div.sy.sy-ta-checkbox');
}

it('renders the checkbox skin bound to the per-cell controller', function (): void {
    $cell = checkboxCell(CheckboxColumn::make('isExplicit'), true);
    $input = $cell->filter('input.sy-checkbox');

    expect($cell->attr('data-controller'))->toBe('sylament--tables--editable-cell')
        ->and($cell->attr('data-sylament--tables--editable-cell-column-value'))->toBe('isExplicit')
        ->and($cell->attr('data-sylament--tables--editable-cell-record-value'))->toBe('7')
        ->and($cell->attr('data-sylament--tables--editable-cell-state-value'))->toBe('1')
        ->and($input->attr('type'))->toBe('checkbox')
        ->and($input->attr('checked'))->not->toBeNull()
        ->and($input->attr('aria-label'))->toBe('Is explicit')
        ->and($input->attr('data-action'))->toBe('change->sylament--tables--editable-cell#commit');
});

it('leaves an unset state unchecked and its cell state zero', function (): void {
    $cell = checkboxCell(CheckboxColumn::make('isExplicit'), null);

    expect($cell->attr('data-sylament--tables--editable-cell-state-value'))->toBe('0')
        ->and($cell->filter('input')->attr('checked'))->toBeNull();
});

it('merges the column extra input attributes onto the control', function (): void {
    $cell = checkboxCell(CheckboxColumn::make('isExplicit')->extraInputAttributes(['data-role' => 'flag']), false);

    expect($cell->filter('input')->attr('data-role'))->toBe('flag')
        ->and($cell->filter('input')->attr('data-action'))->toBe('change->sylament--tables--editable-cell#commit');
});

it('disables the control the column refuses', function (): void {
    expect(checkboxCell(CheckboxColumn::make('isExplicit')->disabled(), true)->filter('input')->attr('disabled'))
        ->not->toBeNull();
});
