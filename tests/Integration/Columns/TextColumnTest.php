<?php

declare(strict_types=1);

namespace Sylament\Tables\Tests\Integration\Columns;

use Sylament\Tables\Columns\TextColumn;
use Sylament\Tables\Table;
use Sylament\Tables\Tests\Fixtures\Concerns\TableHost;
use Sylament\Tables\Tests\Fixtures\Records\Artist;
use Sylament\Tables\Tests\Fixtures\Records\Song;
use Symfony\Component\DomCrawler\Crawler;

use function Sylament\Tables\Tests\renderToHtml;

/*
 * Drives the shipped `TextColumn` partial through a real table render — the
 * state pipeline (dotted paths, the format hook, a null state, an iterable
 * state) landing inside the cell's own `div.sy.sy-ta-text`, selected through
 * the cell chain so the element carrying the state is the asserted one.
 */

it('renders dotted-path and formatted state into cells', function (): void {
    $host = new TableHost(fn (Table $table): Table => $table
        ->records(fn (): array => [new Song('Airbag', 'OK Computer', new Artist('Radiohead'))])
        ->columns([
            TextColumn::make('artist.name'),
            TextColumn::make('title')->formatStateUsing(fn (string $state): string => mb_strtoupper($state)),
        ]));

    $cells = new Crawler(renderToHtml($host->getTable()))
        ->filter('td.sy-ta-cell > div.sy-ta-col > div.sy.sy-ta-text');

    expect($cells->count())->toBe(2)
        ->and($cells->eq(0)->text())->toBe('Radiohead')
        ->and($cells->eq(1)->text())->toBe('AIRBAG');
});

it('renders an empty text element for null state and joins iterable state', function (): void {
    $host = new TableHost(fn (Table $table): Table => $table
        ->records(fn (): array => [['tags' => ['symfony', 'forms'], 'note' => null]])
        ->columns([TextColumn::make('tags'), TextColumn::make('note')]));

    $html = renderToHtml($host->getTable());
    $cells = new Crawler($html)->filter('td.sy-ta-cell > div.sy-ta-col > div.sy.sy-ta-text');

    expect($cells->count())->toBe(2)
        ->and($cells->eq(0)->text())->toBe('symfony, forms')
        ->and($cells->eq(1)->text())->toBe('')
        ->and($html)->toContain('<div class="sy sy-ta-text"></div>');
});
