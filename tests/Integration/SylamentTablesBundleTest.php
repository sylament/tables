<?php

declare(strict_types=1);

namespace Sylament\Tables\Tests\Integration;

use Sylament\Schemas\State\PropertyAccessor;
use Sylament\Support\Evaluation\Invoker;
use Sylament\Tables\Columns\TextColumn;
use Sylament\Tables\Table;
use Sylament\Tables\Tests\Fixtures\Concerns\TableHost;
use Sylament\Tables\Tests\Fixtures\Records\SongRepository;
use Sylament\Tables\Twig\Extension\TableExtension;
use Twig\Loader\FilesystemLoader;

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
