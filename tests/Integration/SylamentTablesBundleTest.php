<?php

declare(strict_types=1);

namespace Sylament\Tables\Tests\Integration;

use Sylament\Forms\Components\Checkbox;
use Sylament\Schemas\Schema;
use Sylament\Schemas\State\PropertyAccessor;
use Sylament\Support\Evaluation\Invoker;
use Sylament\Tables\Columns\Summarizers\Average;
use Sylament\Tables\Columns\Summarizers\Count;
use Sylament\Tables\Columns\Summarizers\Range;
use Sylament\Tables\Columns\Summarizers\Sum;
use Sylament\Tables\Columns\Summarizers\Summarizer;
use Sylament\Tables\Columns\Summarizers\Values;
use Sylament\Tables\Columns\TextColumn;
use Sylament\Tables\Filters\BaseFilter;
use Sylament\Tables\Filters\FilterIndicator;
use Sylament\Tables\Filters\Indicator;
use Sylament\Tables\Grouping\Concerns\BelongsToTable;
use Sylament\Tables\Grouping\Group;
use Sylament\Tables\Table;
use Sylament\Tables\Tests\Fixtures\Concerns\SchemaStateHost;
use Sylament\Tables\Tests\Fixtures\Concerns\TableHost;
use Sylament\Tables\Tests\Fixtures\Records\SongRepository;
use Sylament\Tables\Twig\Extension\TableExtension;
use Symfony\Component\AssetMapper\AssetMapperInterface;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerInterface;
use Twig\Loader\FilesystemLoader;

use function Sylament\Tables\Tests\renderToHtml;

it('renders a support component tag against the fixture icon set', function (): void {
    $html = $this::getContainer()->get('twig')
        ->createTemplate('<twig:SylamentSupport:Icon icon="heroicons:x-mark" />')
        ->render();

    expect($html)
        ->toContain('<svg')
        ->toContain('M6 18 18 6M6 6l12 12');
});

it('maps the @SylamentTables template namespace onto the bundle templates directory', function (): void {
    $loader = $this::getContainer()->get('twig')->getLoader();

    expect($loader)->toBeInstanceOf(FilesystemLoader::class)
        ->and($loader->getNamespaces())->toContain('SylamentTables')
        ->and($loader->exists('@SylamentTables/partials/Table/index.html.twig'))->toBeTrue();
});

it('registers the table twig extension', function (): void {
    expect($this::getContainer()->get('twig')->getExtension(TableExtension::class))
        ->toBeInstanceOf(TableExtension::class);
});

it('builds columns through the container so the property accessor is injected', function (): void {
    $propertyAccessor = $this::getContainer()->get(PropertyAccessor::class);

    $column = TextColumn::make('title')->record(['title' => 'Airbag']);

    expect($column->getPropertyAccessor())->toBe($propertyAccessor)
        ->and($column->getState())->toBe('Airbag');
});

it('builds the table through the container so its closures reach the container', function (): void {
    $invoker = $this::getContainer()->get(Invoker::class);

    $host = new TableHost(fn (Table $table): Table => $table->records(
        fn (SongRepository $songs): array => $songs->all(),
    ));

    expect($host->getTable()->getInvoker())->toBe($invoker)
        ->and(array_values($host->getTableRecords()))->toBe($this::getContainer()->get(SongRepository::class)->all());
});

it('maps the @SylamentForms template namespace into the tables kernel', function (): void {
    $loader = $this::getContainer()->get('twig')->getLoader();

    expect($loader->getNamespaces())->toContain('SylamentForms')
        ->and($loader->exists('@SylamentForms/partials/Checkbox/index.html.twig'))->toBeTrue();
});

it('renders a forms field inside the tables kernel', function (): void {
    $html = renderToHtml(
        Schema::make(new SchemaStateHost())
            ->statePath('data')
            ->components([Checkbox::make('isFeatured')->label('Featured')]),
    );

    expect($html)
        ->toContain('type="checkbox"')
        ->toContain('sy-fo-checkbox')
        ->toContain('Featured');
});

/*
 * The summarizers sit one directory below the columns glob, so the brace form is what
 * registers them at all — and being container-built is what fires the `#[Required]`
 * sanitizer setter that `html()` and `markdown()` refuse to run without.
 */
it('builds every summarizer through the container so its setters are injected', function (string $summarizer): void {
    $invoker = $this::getContainer()->get(Invoker::class);
    $sanitizer = $this::getContainer()->get(HtmlSanitizerInterface::class);

    $instance = $summarizer::make();
    $instance->column(TextColumn::make('plays')->table(Table::make(new TableHost())));

    expect($instance)->toBeInstanceOf($summarizer)
        ->and($instance->getInvoker())->toBe($invoker)
        ->and((string) $instance->html()->formatState('<b>bold</b><script>alert(1)</script>'))->toBe('<b>bold</b>');

    expect($sanitizer)->toBeInstanceOf(HtmlSanitizerInterface::class);
})->with([
    Summarizer::class,
    Average::class,
    Count::class,
    Range::class,
    Sum::class,
    Values::class,
]);

it('builds filters and indicators through the container so their closures reach it', function (): void {
    $invoker = $this::getContainer()->get(Invoker::class);

    expect(BaseFilter::make('status')->getInvoker())->toBe($invoker)
        ->and(Indicator::make('Published')->getInvoker())->toBe($invoker);
});

it('builds groups through the container, one instance per make', function (): void {
    $invoker = $this::getContainer()->get(Invoker::class);

    expect(Group::make('status')->getInvoker())->toBe($invoker)
        ->and(Group::make('status'))->not->toBe(Group::make('status'));
});

it('keeps the group concerns out of the grouping service glob', function (): void {
    expect($this::getContainer()->has(BelongsToTable::class))->toBeFalse();
});

it('keeps the filter indicator carrier out of the filters service glob', function (): void {
    expect($this::getContainer()->has(FilterIndicator::class))->toBeFalse();
});

it('maps the built assets directory into AssetMapper under @sylament/tables', function (): void {
    // StimulusBundle's `ControllersMapGenerator` resolves a `symfony.controllers` entry by
    // calling `getAssetFromSourcePath()` on the package directory plus the declared `main`,
    // and throws when the path sits outside every registered one — so the prepend is what
    // stands between the built controller and a consuming app.
    $asset = $this::getContainer()->get(AssetMapperInterface::class)->getAssetFromSourcePath(
        dirname(__DIR__, 2) . '/assets/dist/controllers/table_controller.js',
    );

    expect($asset)->not->toBeNull()
        ->and($asset->logicalPath)->toBe('@sylament/tables/controllers/table_controller.js');
});

it('maps the column manager controller into AssetMapper beside the table one', function (): void {
    $asset = $this::getContainer()->get(AssetMapperInterface::class)->getAssetFromSourcePath(
        dirname(__DIR__, 2) . '/assets/dist/controllers/column_manager_controller.js',
    );

    expect($asset)->not->toBeNull()
        ->and($asset->logicalPath)->toBe('@sylament/tables/controllers/column_manager_controller.js');
});

it('resolves the live-host helper the table controller imports', function (): void {
    // The per-file build ships `utils/live_host.js` standalone beside the controller that
    // imports it, so its logical path must resolve too or the browser 404s on the import.
    $asset = $this::getContainer()->get(AssetMapperInterface::class)->getAssetFromSourcePath(
        dirname(__DIR__, 2) . '/assets/dist/utils/live_host.js',
    );

    expect($asset)->not->toBeNull()
        ->and($asset->logicalPath)->toBe('@sylament/tables/utils/live_host.js');
});

/*
 * The present half of `markdown()`'s guarded optional integration: the library is in the root
 * vendor, so the conversion runs — and it runs through the container-injected sanitizer, which
 * is the only thing standing between a converted document and raw HTML in the cell.
 */
it('converts and sanitizes markdown through the container-built column', function (): void {
    $this::getContainer();

    $column = TextColumn::make('body')->markdown();

    expect((string) $column->formatState('**bold** <script>alert(1)</script>'))
        ->toContain('<strong>bold</strong>')
        ->not->toContain('<script>');
});

/*
 * The absent half: `league/commonmark` must stay a `suggest`, never a `require`, or the guard
 * in `CanFormatState` is unreachable and the package it names is not optional after all. The two
 * unconditional imports the same file gained are asserted beside it, since the split repo
 * resolves this manifest alone.
 */
it('suggests commonmark and requires the sanitizer and intl the column always reaches', function (): void {
    $manifest = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/composer.json'), true, flags: JSON_THROW_ON_ERROR);

    expect($manifest['suggest'])->toHaveKey('league/commonmark')
        ->and($manifest['require'])->not->toHaveKey('league/commonmark')
        ->and($manifest['require'])->toHaveKey('symfony/html-sanitizer')
        ->and($manifest['require'])->toHaveKey('ext-intl');
});
