<?php

declare(strict_types=1);

namespace Sylament\Tables\Tests\Integration\Columns;

use Sylament\Tables\Columns\SelectColumn;
use Sylament\Tables\Table;
use Sylament\Tables\Tests\Fixtures\Concerns\TableHost;
use Symfony\Component\DomCrawler\Crawler;

use function Sylament\Tables\Tests\renderToHtml;

/*
 * The shipped `SelectColumn` partial through a real table render: forms'
 * `sy-fo-select` skin inside support's `InputWrapper`, in the cell's own
 * `div.sy.sy-ta-select`.
 */

function selectCell(SelectColumn $column, mixed $state): Crawler
{
    $host = new TableHost(fn (Table $table): Table => $table
        ->records(fn (): array => [['__key' => '7', 'mood' => $state]])
        ->columns([$column]));

    return new Crawler(renderToHtml($host->getTable()))
        ->filter('td.sy-ta-cell > div.sy-ta-col > div.sy.sy-ta-select');
}

it('renders the select inside the input wrapper, bound to the per-cell controller', function (): void {
    $cell = selectCell(SelectColumn::make('mood')->options(['calm' => 'Calm', 'wild' => 'Wild']), 'wild');
    $select = $cell->filter('div.sy-input-wrp select.sy-fo-select');

    expect($cell->attr('data-controller'))->toBe('sylament--tables--editable-cell')
        ->and($cell->attr('data-sylament--tables--editable-cell-state-value'))->toBe('wild')
        ->and($select->attr('aria-label'))->toBe('Mood')
        ->and($select->attr('data-action'))->toBe('change->sylament--tables--editable-cell#commit')
        ->and($select->filter('option')->extract(['value']))->toBe(['', 'calm', 'wild'])
        ->and($select->filter('option[selected]')->attr('value'))->toBe('wild');
});

it('renders the placeholder option, selected while the record holds nothing', function (): void {
    $cell = selectCell(SelectColumn::make('mood')->options(['calm' => 'Calm']), null);
    $placeholder = $cell->filter('option')->eq(0);

    expect($placeholder->attr('value'))->toBe('')
        ->and($placeholder->text())->toBe('Select an option')
        ->and($placeholder->attr('selected'))->not->toBeNull();
});

it('drops the placeholder option when the column refuses one', function (): void {
    $cell = selectCell(SelectColumn::make('mood')->options(['calm' => 'Calm'])->selectablePlaceholder(false), 'calm');

    expect($cell->filter('option')->extract(['value']))->toBe(['calm']);
});

it('groups options and marks the disabled ones', function (): void {
    $cell = selectCell(
        SelectColumn::make('mood')
            ->options(['Quiet' => ['calm' => 'Calm'], 'Loud' => ['wild' => 'Wild']])
            ->disableOptionWhen(fn (mixed $value): bool => $value === 'wild'),
        'calm',
    );

    expect($cell->filter('optgroup')->extract(['label']))->toBe(['Quiet', 'Loud'])
        ->and($cell->filter('optgroup[label="Loud"] > option')->attr('disabled'))->not->toBeNull()
        ->and($cell->filter('optgroup[label="Quiet"] > option')->attr('disabled'))->toBeNull();
});

it('selects an integer-keyed option against the string the browser posts', function (): void {
    $cell = selectCell(SelectColumn::make('mood')->options([1 => 'Calm', 2 => 'Wild']), 2);

    expect($cell->attr('data-sylament--tables--editable-cell-state-value'))->toBe('2')
        ->and($cell->filter('option[selected]')->attr('value'))->toBe('2');
});

it('disables the control the column refuses, and blanks its placeholder', function (): void {
    $cell = selectCell(SelectColumn::make('mood')->options(['calm' => 'Calm'])->disabled(), null);

    expect($cell->filter('select')->attr('disabled'))->not->toBeNull()
        ->and($cell->filter('option')->eq(0)->text())->toBe('');
});
