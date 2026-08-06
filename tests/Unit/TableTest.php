<?php

declare(strict_types=1);

use Sylament\Tables\Columns\Column;
use Sylament\Tables\Columns\TextColumn;
use Sylament\Tables\Contracts\InteractsWithTable;
use Sylament\Tables\Table;
use Sylament\Tables\Tests\Fixtures\Concerns\TableHost;
use Twig\Markup;

it('binds the live component on make', function (): void {
    $host = new TableHost();

    $table = Table::make($host);

    expect($table->getLiveComponent())->toBe($host);
});

it('stores and returns the records data source', function (): void {
    $dataSource = fn (): array => [];

    $table = Table::make(new TableHost())->records($dataSource);

    expect($table->getDataSource())->toBe($dataSource);
});

it('delegates the record key to the host', function (): void {
    $table = Table::make(new TableHost());

    expect($table->getRecordKey(['__key' => '7']))->toBe('7');
});

it('defaults the empty state heading and leaves description and icon unset', function (): void {
    $table = Table::make(new TableHost());

    expect($table->getEmptyStateHeading())->toBe('No records')
        ->and($table->getEmptyStateDescription())->toBeNull()
        ->and($table->getEmptyStateIcon())->toBeNull();
});

it('passes a markup empty state icon through untouched', function (): void {
    $icon = new Markup('<svg data-icon="empty"></svg>', 'UTF-8');

    $table = Table::make(new TableHost())->emptyStateIcon($icon);

    expect($table->getEmptyStateIcon())->toBe($icon);
});

it('evaluates empty state values with table and live component injections', function (): void {
    $host = new TableHost();

    $table = Table::make($host)
        ->emptyStateHeading(fn ($liveComponent): string => $liveComponent instanceof InteractsWithTable ? 'from host' : 'wrong')
        ->emptyStateDescription(fn (Table $table): string => $table->getEmptyStateHeading());

    expect($table->getEmptyStateHeading())->toBe('from host')
        ->and($table->getEmptyStateDescription())->toBe('from host');
});

it('keys columns by name and binds itself to each', function (): void {
    $table = Table::make(new TableHost());
    $title = TextColumn::make('title');

    $table->columns([$title, TextColumn::make('slug')]);

    expect(array_keys($table->getColumns()))->toBe(['title', 'slug'])
        ->and($table->getColumn('title'))->toBe($title)
        ->and($title->getTable())->toBe($table);
});

it('resets previously declared columns on a second columns() call', function (): void {
    $table = Table::make(new TableHost())
        ->columns([TextColumn::make('title')])
        ->columns([TextColumn::make('slug')]);

    expect(array_keys($table->getColumns()))->toBe(['slug']);
});

it('is paginated by default with Filament page options', function (): void {
    $table = new TableHost()->getTable();

    expect($table->isPaginated())->toBeTrue()
        ->and($table->getPaginationPageOptions())->toBe([5, 10, 25, 50])
        ->and($table->getDefaultPaginationPageOption())->toBe(10)
        ->and($table->hasExtremePaginationLinks())->toBeFalse();
});

it('turns pagination off and back on', function (): void {
    $off = new TableHost(fn (Table $t): Table => $t->paginated(false))->getTable();
    $backOn = new TableHost(fn (Table $t): Table => $t->paginated(false)->paginated(true))->getTable();

    expect($off->isPaginated())->toBeFalse()
        ->and($backOn->isPaginated())->toBeTrue();
});

it('turns the extreme pagination links on', function (): void {
    $table = new TableHost(fn (Table $t): Table => $t->extremePaginationLinks())->getTable();

    expect($table->hasExtremePaginationLinks())->toBeTrue();
});

it('routes a paginated() array into the page options', function (): void {
    $table = new TableHost(fn (Table $t): Table => $t->paginated([12, 24]))->getTable();

    expect($table->isPaginated())->toBeTrue()
        ->and($table->getPaginationPageOptions())->toBe([12, 24])
        ->and($table->getDefaultPaginationPageOption())->toBe(12);
});

it('prefers ten as the default page option, else the first', function (): void {
    $withTen = new TableHost(fn (Table $t): Table => $t->paginationPageOptions([25, 10, 50]))->getTable();
    $without = new TableHost(fn (Table $t): Table => $t->paginationPageOptions([25, 50, 'all']))->getTable();

    expect($withTen->getDefaultPaginationPageOption())->toBe(10)
        ->and($without->getDefaultPaginationPageOption())->toBe(25);
});

it('honours an explicit default page option', function (): void {
    $table = new TableHost(fn (Table $t): Table => $t->defaultPaginationPageOption(25))->getTable();

    expect($table->getDefaultPaginationPageOption())->toBe(25);
});

it('resolves a sortable visible column and refuses the rest', function (): void {
    $table = new TableHost(fn (Table $t): Table => $t->columns([
        TextColumn::make('title')->sortable(),
        TextColumn::make('slug'),
    ]))->getTable();

    expect($table->getSortableVisibleColumn('title'))->toBeInstanceOf(Column::class)
        ->and($table->getSortableVisibleColumn('slug'))->toBeNull()
        ->and($table->getSortableVisibleColumn('missing'))->toBeNull();
});

it('is searchable exactly when a column is globally searchable', function (): void {
    $searchable = new TableHost(fn (Table $t): Table => $t->columns([TextColumn::make('title')->searchable()]))->getTable();
    $individualOnly = new TableHost(fn (Table $t): Table => $t->columns([TextColumn::make('title')->searchable(isIndividual: true, isGlobal: false)]))->getTable();
    $plain = new TableHost(fn (Table $t): Table => $t->columns([TextColumn::make('title')]))->getTable();

    expect($searchable->isSearchable())->toBeTrue()
        ->and($individualOnly->isSearchable())->toBeFalse()
        ->and($plain->isSearchable())->toBeFalse();
});

it('lets searchable() override the column-derived answer in both directions', function (): void {
    $forcedOn = new TableHost(fn (Table $t): Table => $t->searchable())->getTable();
    $forcedOff = new TableHost(fn (Table $t): Table => $t->searchable(false)->columns([TextColumn::make('title')->searchable()]))->getTable();
    $extra = new TableHost(fn (Table $t): Table => $t->searchable(['author.name']))->getTable();

    expect($forcedOn->isSearchable())->toBeTrue()
        ->and($forcedOff->isSearchable())->toBeFalse()
        ->and($extra->isSearchable())->toBeTrue()
        ->and($extra->getExtraSearchableColumns())->toBe(['author.name']);
});

it('carries the search chrome configuration', function (): void {
    $table = new TableHost(fn (Table $t): Table => $t
        ->searchPlaceholder('Find an article')
        ->searchDebounce('750ms')
        ->searchOnBlur())->getTable();

    expect($table->getSearchPlaceholder())->toBe('Find an article')
        ->and($table->getSearchDebounce())->toBe('750ms')
        ->and($table->getSearchDebounceMilliseconds())->toBe(750)
        ->and($table->isSearchOnBlur())->toBeTrue();
});

it('translates debounce durations to milliseconds with a 500 fallback', function (): void {
    $table = fn (?string $debounce): Table => new TableHost(fn (Table $t): Table => $t->searchDebounce($debounce))->getTable();

    expect($table(null)->getSearchDebounceMilliseconds())->toBe(500)
        ->and($table('1s')->getSearchDebounceMilliseconds())->toBe(1000)
        ->and($table('250')->getSearchDebounceMilliseconds())->toBe(250)
        ->and($table('bogus')->getSearchDebounceMilliseconds())->toBe(500);
});
