<?php

declare(strict_types=1);

namespace Sylament\Tables\Tests\Integration;

use Sylament\Forms\Components\Checkbox;
use Sylament\Schemas\Schema;
use Sylament\Schemas\State\PropertyAccessor;
use Sylament\Support\Evaluation\Invoker;
use Sylament\Tables\Columns\TextColumn;
use Sylament\Tables\Filters\BaseFilter;
use Sylament\Tables\Filters\FilterIndicator;
use Sylament\Tables\Filters\Indicator;
use Sylament\Tables\Table;
use Sylament\Tables\Tests\Fixtures\Concerns\SchemaStateHost;
use Sylament\Tables\Tests\Fixtures\Concerns\TableHost;
use Sylament\Tables\Tests\Fixtures\Records\SongRepository;
use Sylament\Tables\Twig\Extension\TableExtension;
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

it('builds filters and indicators through the container so their closures reach it', function (): void {
    $invoker = $this::getContainer()->get(Invoker::class);

    expect(BaseFilter::make('status')->getInvoker())->toBe($invoker)
        ->and(Indicator::make('Published')->getInvoker())->toBe($invoker);
});

it('keeps the filter indicator carrier out of the filters service glob', function (): void {
    expect($this::getContainer()->has(FilterIndicator::class))->toBeFalse();
});
