<?php

declare(strict_types=1);

namespace Sylament\Tables\Tests\Integration\Columns;

use LogicException;
use Sylament\Tables\Columns\ViewColumn;
use Sylament\Tables\Table;
use Sylament\Tables\Tests\Fixtures\Concerns\TableHost;
use Symfony\Component\DomCrawler\Crawler;

use function Sylament\Tables\Tests\renderToHtml;

/*
 * The escape-hatch column: whatever the user's template renders, with the column in scope as
 * `component` and nothing else — the record is one getter away rather than a second variable.
 */

function viewCell(ViewColumn $column): Crawler
{
    $host = new TableHost(fn (Table $table): Table => $table
        ->records(fn (): array => [['title' => 'Airbag']])
        ->columns([$column]));

    return new Crawler(renderToHtml($host->getTable()))
        ->filter('td.sy-ta-cell > div.sy-ta-col > div.sy-ta-view-fixture');
}

it('renders the template with the column in scope as component', function (): void {
    $cell = viewCell(ViewColumn::make('title')->template('@TablesFixtures/partials/ViewColumnFixture/index.html.twig'));

    expect($cell->count())->toBe(1)
        ->and($cell->filter('span.name')->text())->toBe('title')
        ->and($cell->filter('span.state')->text())->toBe('Airbag')
        ->and($cell->filter('span.record')->text())->toBe('Airbag');
});

it('exposes no record variable of its own', function (): void {
    $cell = viewCell(ViewColumn::make('title')->template('@TablesFixtures/partials/ViewColumnFixture/index.html.twig'));

    expect($cell->filter('span.record-variable')->count())->toBe(0);
});

it('merges template data into the scope', function (): void {
    $column = ViewColumn::make('title')
        ->template('@TablesFixtures/partials/ViewColumnFixture/index.html.twig', ['badge' => 'Featured']);

    expect(viewCell($column)->filter('span.badge')->text())->toBe('Featured');
});

it('refuses to render without a template', function (): void {
    expect(fn (): string => renderToHtml(ViewColumn::make('title')))
        ->toThrow(LogicException::class, 'has no template');
});
