<?php

declare(strict_types=1);

namespace Sylament\Tables\Tests\Integration\Columns;

use Sylament\Support\Filesystem\FilesystemRegistry;
use Sylament\Tables\Columns\ImageColumn;
use Sylament\Tables\Table;
use Sylament\Tables\Tests\Fixtures\Concerns\TableHost;
use Symfony\Component\DomCrawler\Crawler;
use Twig\Markup;

use function Sylament\Tables\Tests\renderToHtml;

/*
 * Drives the shipped `ImageColumn` partial through a real table render — the URL-state arm,
 * since the fixture kernel registers no flysystem storage: the plain, circular, stacked and
 * blank shapes landing inside the cell's own `div.sy.sy-ta-image`.
 */

function imageCell(ImageColumn $column, mixed $state): Crawler
{
    $host = new TableHost(fn (Table $table): Table => $table
        ->records(fn (): array => [['photo' => $state]])
        ->columns([$column]));

    return new Crawler(renderToHtml($host->getTable()))
        ->filter('td.sy-ta-cell > div.sy-ta-col > div.sy.sy-ta-image');
}

it('renders one img per url with the default height', function (): void {
    $cell = imageCell(ImageColumn::make('photo'), 'https://example.com/a.png');
    $img = $cell->filter('img');

    expect($img->count())->toBe(1)
        ->and($img->attr('src'))->toBe('https://example.com/a.png')
        ->and($img->attr('alt'))->toBe('')
        ->and($img->attr('style'))->toBe('height: 2.5rem');
});

it('marks a circular column and squares the dimensions', function (): void {
    $cell = imageCell(ImageColumn::make('photo')->circular()->imageSize(40), 'https://example.com/a.png');

    expect($cell->attr('class'))->toContain('sy-circular')
        ->and($cell->filter('img')->attr('style'))->toBe('height: 40px; width: 40px');
});

it('stacks images with the ring, the default overlap and the remaining-text chip', function (): void {
    $column = ImageColumn::make('photo')->stacked()->ring(2)->limit(2)->limitedRemainingText(size: 'lg');
    $cell = imageCell($column, ['https://a.example/1.png', 'https://a.example/2.png', 'https://a.example/3.png', 'https://a.example/4.png']);

    $chip = $cell->filter('div.sy-ta-image-limited-remaining-text');

    expect($cell->attr('class'))->toContain('sy-stacked')
        ->and($cell->attr('class'))->toContain('sy-ta-image-ring-2')
        ->and($cell->attr('class'))->toContain('sy-ta-image-overlap-2')
        ->and($cell->filter('img')->count())->toBe(2)
        ->and($chip->text())->toBe('+2')
        ->and($chip->attr('class'))->toContain('sy-size-lg')
        ->and($chip->attr('style'))->toBe('height: 2rem');
});

it('renders an empty element for a blank state', function (): void {
    $cell = imageCell(ImageColumn::make('photo'), null);

    expect($cell->count())->toBe(1)
        ->and($cell->filter('img')->count())->toBe(0)
        ->and($cell->filter('p.sy-ta-placeholder')->count())->toBe(0);
});

it('renders the placeholder paragraph and the empty tooltip for a blank state', function (): void {
    $cell = imageCell(ImageColumn::make('photo')->placeholder('No cover')->emptyTooltip('Nothing uploaded'), null);

    expect($cell->filter('p.sy-ta-placeholder')->text())->toBe('No cover')
        ->and($cell->attr('title'))->toBe('Nothing uploaded');
});

it('flattens a markup empty tooltip to text', function (): void {
    $cell = imageCell(ImageColumn::make('photo')->emptyTooltip(new Markup('<b>Nothing</b> here', 'UTF-8')), null);

    expect($cell->attr('title'))->toBe('Nothing here');
});

it('lets the default image beat the placeholder', function (): void {
    $cell = imageCell(ImageColumn::make('photo')->placeholder('No cover')->defaultImageUrl('/images/placeholder.png'), null);

    expect($cell->filter('p.sy-ta-placeholder')->count())->toBe(0)
        ->and($cell->filter('img')->attr('src'))->toBe('/images/placeholder.png');
});

it('marks the root inline and spreads the wrapper extra attributes', function (): void {
    $cell = imageCell(ImageColumn::make('photo')->inline()->extraAttributes(['data-probe' => 'yes']), 'https://example.com/a.png');

    expect($cell->attr('class'))->toContain('sy-inline')
        ->and($cell->attr('data-probe'))->toBe('yes');
});

it('titles each image with the tooltip its own state resolves', function (): void {
    $cell = imageCell(
        ImageColumn::make('photo')->tooltip(fn (mixed $state): string => 'Open ' . $state),
        ['https://a.example/1.png', 'https://a.example/2.png'],
    );

    expect($cell->filter('img')->each(fn (Crawler $img): ?string => $img->attr('title')))
        ->toBe(['Open https://a.example/1.png', 'Open https://a.example/2.png']);
});

it('wraps each image in a state-based anchor and opens it in the configured tab', function (): void {
    $cell = imageCell(
        ImageColumn::make('photo')->url(fn (mixed $state): string => '/view?src=' . $state, shouldOpenInNewTab: true),
        ['https://a.example/1.png', 'https://a.example/2.png'],
    );

    $anchors = $cell->filter('a');

    expect($anchors->count())->toBe(2)
        ->and($anchors->each(fn (Crawler $anchor): ?string => $anchor->attr('href')))
        ->toBe(['/view?src=https://a.example/1.png', '/view?src=https://a.example/2.png'])
        ->and($anchors->first()->attr('target'))->toBe('_blank')
        ->and($anchors->first()->filter('img')->count())->toBe(1);
});

it('leaves an image unwrapped when the state-based url refuses it', function (): void {
    $cell = imageCell(
        ImageColumn::make('photo')->url(fn (mixed $state): string => 'javascript:alert(1)'),
        'https://a.example/1.png',
    );

    expect($cell->filter('a')->count())->toBe(0)
        ->and($cell->filter('img')->count())->toBe(1);
});

it('renders the default image for a blank state', function (): void {
    $cell = imageCell(ImageColumn::make('photo')->defaultImageUrl('/images/placeholder.png'), null);

    expect($cell->filter('img')->attr('src'))->toBe('/images/placeholder.png');
});

it('spreads the extra img attributes onto each image', function (): void {
    $cell = imageCell(ImageColumn::make('photo')->extraImgAttributes(['loading' => 'lazy']), 'https://example.com/a.png');

    expect($cell->filter('img')->attr('loading'))->toBe('lazy');
});

it('carries the wrap and alignment classes on the root', function (): void {
    $cell = imageCell(ImageColumn::make('photo')->wrap()->alignCenter(), 'https://example.com/a.png');

    expect($cell->attr('class'))->toContain('sy-wrapped')
        ->and($cell->attr('class'))->toContain('sy-align-center');
});

/*
 * The disk arm, over the kernel's two real local storages: `test.storage` is the default and
 * the only one that can mint a URL. The kernel must boot before `make()` so the container
 * builds the column and the filesystem registry reaches it.
 */

it('mints a public url for a stored file on the default disk', function (): void {
    $this::getContainer()->get(FilesystemRegistry::class)->get()->write('covers/a.png', 'the bytes');

    $cell = imageCell(ImageColumn::make('photo'), 'covers/a.png');

    expect($cell->filter('img')->attr('src'))->toBe('/files/covers/a.png');
});

it('drops the image for a missing file', function (): void {
    $this::getContainer();

    $cell = imageCell(ImageColumn::make('photo'), 'covers/missing-' . bin2hex(random_bytes(4)) . '.png');

    expect($cell->filter('img')->attr('src'))->toBe('');
});

it('touches the storage not at all when the existence check is off and the visibility declared', function (): void {
    // The file does not exist, yet the URL is minted: no existence check ran, and the declared
    // visibility means no per-file visibility read either. Without `visibility('public')` the
    // registry would still stat the file and read the missing one as private.
    $this::getContainer();

    $cell = imageCell(ImageColumn::make('photo')->checkFileExistence(false)->visibility('public'), 'covers/unchecked.png');

    expect($cell->filter('img')->attr('src'))->toBe('/files/covers/unchecked.png');
});

it('hands the browser no url for a private file the storage cannot sign', function (): void {
    $this::getContainer()->get(FilesystemRegistry::class)->get('other.storage')->write('covers/b.png', 'the bytes');

    $cell = imageCell(ImageColumn::make('photo')->disk('other.storage')->visibility('private'), 'covers/b.png');

    expect($cell->filter('img')->attr('src'))->toBe('');
});
