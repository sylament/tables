<?php

declare(strict_types=1);

namespace Sylament\Tables\Tests\Integration\Columns;

use Sylament\Tables\Columns\TextInputColumn;
use Sylament\Tables\Table;
use Sylament\Tables\Tests\Fixtures\Concerns\TableHost;
use Symfony\Component\DomCrawler\Crawler;

use function Sylament\Tables\Tests\renderToHtml;

/*
 * The shipped `TextInputColumn` partial through a real table render: forms'
 * `sy-fo-input` skin inside support's `InputWrapper`, in the cell's own
 * `div.sy.sy-ta-text-input`.
 */

function textInputCell(TextInputColumn $column, mixed $state): Crawler
{
    $host = new TableHost(fn (Table $table): Table => $table
        ->records(fn (): array => [['__key' => '7', 'title' => $state]])
        ->columns([$column]));

    return new Crawler(renderToHtml($host->getTable()))
        ->filter('td.sy-ta-cell > div.sy-ta-col > div.sy.sy-ta-text-input');
}

it('renders the input inside the wrapper, bound to the per-cell controller', function (): void {
    $cell = textInputCell(TextInputColumn::make('title'), 'Airbag');
    $input = $cell->filter('div.sy-input-wrp input.sy-fo-input');

    expect($cell->attr('data-controller'))->toBe('sylament--tables--editable-cell')
        ->and($cell->attr('data-sylament--tables--editable-cell-column-value'))->toBe('title')
        ->and($cell->attr('data-sylament--tables--editable-cell-state-value'))->toBe('Airbag')
        ->and($input->attr('type'))->toBe('text')
        ->and($input->attr('value'))->toBe('Airbag')
        ->and($input->attr('aria-label'))->toBe('Title')
        ->and($input->attr('data-action'))->toBe('change->sylament--tables--editable-cell#commit');
});

it('renders an empty value rather than dropping the attribute for a blank record', function (): void {
    $input = textInputCell(TextInputColumn::make('title'), null)->filter('input');

    expect($input->attr('value'))->toBe('')
        ->and($input->attr('placeholder'))->toBeNull();
});

it('carries the type, input mode, step and placeholder the column sets', function (): void {
    $column = TextInputColumn::make('title')->type('number')->inputMode('numeric')->step(5)->placeholder('Untitled');
    $input = textInputCell($column, null)->filter('input');

    expect($input->attr('type'))->toBe('number')
        ->and($input->attr('inputmode'))->toBe('numeric')
        ->and($input->attr('step'))->toBe('5')
        ->and($input->attr('placeholder'))->toBe('Untitled');
});

it('renders the affixes through the input wrapper', function (): void {
    $cell = textInputCell(TextInputColumn::make('title')->prefix('#', isInline: true)->suffix('kg'), 'Airbag');

    expect($cell->filter('div.sy-input-wrp')->text())->toContain('#')
        ->and($cell->filter('div.sy-input-wrp')->text())->toContain('kg')
        ->and($cell->filter('input')->attr('class'))->toContain('sy-fo-input-has-inline-prefix');
});

it('disables the control the column refuses', function (): void {
    expect(textInputCell(TextInputColumn::make('title')->disabled(), 'Airbag')->filter('input')->attr('disabled'))
        ->not->toBeNull();
});
