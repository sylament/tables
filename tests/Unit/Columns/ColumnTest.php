<?php

declare(strict_types=1);

use Doctrine\ORM\EntityManager;
use Sylament\Tables\Columns\Column;
use Sylament\Tables\Columns\TextColumn;
use Sylament\Tables\Table;
use Sylament\Tables\Tests\Fixtures\Concerns\TableHost;
use Sylament\Tables\Tests\Fixtures\Doctrine\Entity\Artist as DoctrineArtist;
use Sylament\Tables\Tests\Fixtures\Doctrine\Entity\Genre;
use Sylament\Tables\Tests\Fixtures\Doctrine\Entity\Song as DoctrineSong;
use Sylament\Tables\Tests\Fixtures\Doctrine\EntityManagerFactory;
use Sylament\Tables\Tests\Fixtures\Records\Artist;
use Sylament\Tables\Tests\Fixtures\Records\Song;

/**
 * An unmanaged song carrying the named genres, so a to-many path has a subject
 * that needs no database behind it.
 */
function songWithGenres(string ...$names): DoctrineSong
{
    $song = new DoctrineSong(1, 'Airbag', 10);

    foreach ($names as $index => $name) {
        $song->getGenres()->add(new Genre($index + 1, $name));
    }

    return $song;
}

/**
 * Two seeded songs — one on an artist that owns it, one with no artist at all —
 * so a path crossing a collection has a populated row and a row whose walk
 * stops before the collection.
 */
function songLibrary(): EntityManager
{
    $entityManager = EntityManagerFactory::create();
    $radiohead = new DoctrineArtist(1, 'Radiohead');

    EntityManagerFactory::seed(
        $entityManager,
        $radiohead,
        new DoctrineSong(1, 'Alpha', 10, $radiohead),
        new DoctrineSong(2, 'Orphan', 20),
    );

    return $entityManager;
}

it('requires a name on make', function (): void {
    expect(fn (): Column => Column::make())
        ->toThrow(LogicException::class, 'must have a unique name');
});

it('derives the label from the column name', function (string $name, string $label): void {
    expect(Column::make($name)->getLabel())->toBe($label);
})->with([
    ['title', 'Title'],
    ['releasedAt', 'Released at'],
    ['artist.name', 'Artist'],
]);

it('prefers an explicit label and evaluates closures with the column injected', function (): void {
    expect(Column::make('title')->label('Song')->getLabel())->toBe('Song')
        ->and(Column::make('title')->label(fn (Column $column): string => $column->getName())->getLabel())->toBe('title');
});

it('reads state from an object record by property path', function (): void {
    $column = Column::make('title')->record(new Song('Airbag', 'OK Computer'));

    expect($column->getState())->toBe('Airbag');
});

it('reads dotted state through a nested object', function (): void {
    $column = Column::make('artist.name')->record(new Song('Airbag', 'OK Computer', new Artist('Radiohead')));

    expect($column->getState())->toBe('Radiohead');
});

it('reads state from an array record', function (): void {
    $column = Column::make('title')->record(['title' => 'Let Down']);

    expect($column->getState())->toBe('Let Down');
});

it('returns null for an unreadable path instead of throwing', function (): void {
    expect(Column::make('missing')->record(new Song('Airbag', 'OK Computer'))->getState())->toBeNull()
        ->and(Column::make('missing')->record(['title' => 'x'])->getState())->toBeNull()
        ->and(Column::make('title')->getState())->toBeNull();
});

it('flattens a to-many path into a list', function (): void {
    expect(Column::make('genres.name')->record(songWithGenres('Rock', 'Pop'))->getState())->toBe(['Rock', 'Pop']);
});

it('keeps a single-element to-many result a list', function (): void {
    expect(Column::make('genres.name')->record(songWithGenres('Rock'))->getState())->toBe(['Rock']);
});

it('drops blank values from a flattened list', function (): void {
    expect(Column::make('genres.name')->record(songWithGenres('', 'Pop'))->getState())->toBe(['Pop']);
});

it('dedupes a flattened list only once distinctList is set', function (): void {
    $record = songWithGenres('Rock', 'Rock', 'Pop');

    expect(Column::make('genres.name')->record($record)->getState())->toBe(['Rock', 'Rock', 'Pop'])
        ->and(Column::make('genres.name')->distinctList()->record($record)->getState())->toBe(['Rock', 'Pop'])
        ->and(Column::make('genres.name')->distinctList(fn (): bool => true)->record($record)->getState())->toBe(['Rock', 'Pop'])
        ->and(Column::make('genres.name')->distinctList(false)->record($record)->getState())->toBe(['Rock', 'Rock', 'Pop']);
});

it('yields null when a to-many path collects nothing', function (): void {
    expect(Column::make('genres.name')->record(songWithGenres())->getState())->toBeNull();
});

/*
 * The record read keeps a blank to-one leaf where Filament's relationship arm drops it;
 * `getState()`'s blank-to-default arm then collapses the pair anyway, so the divergence is
 * visible only one level down. Asserting both is what keeps it that way.
 */
it('reads a to-one chain unchanged, blank leaf included', function (): void {
    expect(Column::make('artist.name')->record(new Song('Airbag', 'OK Computer', new Artist('')))->getStateFromRecord())->toBe('')
        ->and(Column::make('artist.name')->record(new Song('Airbag', 'OK Computer', new Artist('')))->getState())->toBeNull()
        ->and(Column::make('artist.name')->record(new Song('Airbag', 'OK Computer', new Artist('')))->default('—')->getState())->toBe('—')
        ->and(Column::make('artist.name')->record(new Song('Airbag', 'OK Computer'))->getState())->toBeNull();
});

it('leaves a dotless collection name to the raw record read', function (): void {
    $record = songWithGenres('Rock', 'Pop');

    expect(Column::make('genres')->record($record)->getState())->toBe($record->getGenres());
});

it('flattens a path that ends at a collection into its items', function (): void {
    $entityManager = songLibrary();

    $state = Column::make('artist.songs')->record($entityManager->getReference(DoctrineSong::class, 1))->getState();

    expect($state)->toBeArray()
        ->and($state)->toHaveCount(1)
        ->and($state[0])->toBeInstanceOf(DoctrineSong::class);
});

it('keeps the to-many shape on a row whose walk reaches no collection', function (): void {
    $entityManager = songLibrary();
    $table = new TableHost(fn (Table $table): Table => $table
        ->query($entityManager->createQueryBuilder()->select('song')->from(DoctrineSong::class, 'song')))->getTable();
    $stateOf = fn (int $id): mixed => Column::make('artist.songs.title')
        ->table($table)
        ->record($entityManager->getReference(DoctrineSong::class, $id))
        ->getState();

    expect($stateOf(2))->toBeNull()
        ->and($stateOf(1))->toBe(['Alpha']);
});

it('falls back to value-derived multiplicity on the records lane', function (): void {
    $record = songWithGenres('Rock', 'Pop');
    $table = new TableHost(fn (Table $table): Table => $table->records(fn (): array => [$record]))->getTable();

    expect($table->getQueryEntityManager())->toBeNull()
        ->and($table->getRecordClass())->toBeNull()
        ->and(Column::make('genres.name')->table($table)->record($record)->getState())->toBe(['Rock', 'Pop']);
});

it('lets state() override the record read and injects the record', function (): void {
    $column = Column::make('title')
        ->record(['title' => 'ignored', 'album' => 'In Rainbows'])
        ->state(fn ($record): string => $record['album']);

    expect($column->getState())->toBe('In Rainbows');
});

it('binds its table and reaches the live component through it', function (): void {
    $host = new TableHost();
    $table = Table::make($host);
    $column = TextColumn::make('title')->table($table);

    expect($column->getTable())->toBe($table)
        ->and($column->getLiveComponent())->toBe($host);
});

it('injects the cell state, the live component and the bound table by name', function (): void {
    $host = new TableHost();
    $boundTable = Table::make($host);
    $record = new Song('Airbag', 'OK Computer');

    $makeColumn = fn (): TextColumn => TextColumn::make('title')->table($boundTable)->record($record);

    expect($makeColumn()->label(fn (string $state): string => $state)->getLabel())->toBe('Airbag')
        ->and($makeColumn()->state(fn ($liveComponent) => $liveComponent)->getState())->toBe($host)
        ->and($makeColumn()->state(fn (Table $table): Table => $table)->getState())->toBe($boundTable);
});

it('injects itself into a differently named parameter type-hinted as the column', function (): void {
    $column = TextColumn::make('title')->table(Table::make(new TableHost()));

    expect($column->state(fn (Column $boundColumn): Column => $boundColumn)->getState())->toBe($column);
});

it('casts an integer record key to a string and takes null back', function (): void {
    $column = Column::make('title');

    expect($column->getRecordKey())->toBeNull()
        ->and($column->recordKey(7)->getRecordKey())->toBe('7')
        ->and($column->recordKey('7')->getRecordKey())->toBe('7')
        ->and($column->recordKey(null)->getRecordKey())->toBeNull();
});

it('caches state per record key and re-reads for a new key', function (): void {
    $column = TextColumn::make('title');
    $column->record(['title' => 'Airbag', '__key' => '1'])->recordKey('1');

    expect($column->getState())->toBe('Airbag');

    $column->record(['title' => 'Changed', '__key' => '1']);

    expect($column->getState())->toBe('Airbag');

    $column->record(['title' => 'Let Down', '__key' => '2'])->recordKey('2');

    expect($column->getState())->toBe('Let Down');
});

it('caches a null state rather than reading the record again', function (): void {
    $column = TextColumn::make('title');
    $column->record(['title' => null, '__key' => '1'])->recordKey('1');

    expect($column->getState())->toBeNull();

    $column->record(['title' => 'Airbag', '__key' => '1']);

    expect($column->getState())->toBeNull();
});

it('reads fresh every time without a record key', function (): void {
    $column = TextColumn::make('title');
    $column->record(['title' => 'Airbag']);

    expect($column->getState())->toBe('Airbag');

    $column->record(['title' => 'Changed']);

    expect($column->getState())->toBe('Changed');
});

it('clears the cached state', function (): void {
    $column = TextColumn::make('title');
    $column->record(['title' => 'Airbag'])->recordKey('1');
    $column->getState();
    $column->record(['title' => 'Changed']);
    $column->clearCachedState();

    expect($column->getState())->toBe('Changed');
});

it('is not sortable by default', function (): void {
    expect(TextColumn::make('title')->isSortable())->toBeFalse();
});

it('is sortable through the flag, a closure, and the column-list arm', function (): void {
    expect(TextColumn::make('title')->sortable()->isSortable())->toBeTrue()
        ->and(TextColumn::make('title')->sortable(false)->isSortable())->toBeFalse()
        ->and(TextColumn::make('title')->sortable(fn (): bool => true)->isSortable())->toBeTrue()
        ->and(TextColumn::make('title')->sortable(['title', 'subtitle'])->isSortable())->toBeTrue();
});

it('is not searchable by default', function (): void {
    $column = TextColumn::make('title');

    expect($column->isSearchable())->toBeFalse()
        ->and($column->isGloballySearchable())->toBeFalse()
        ->and($column->isIndividuallySearchable())->toBeFalse();
});

it('is globally searchable by default once marked searchable', function (): void {
    $column = TextColumn::make('title')->searchable();

    expect($column->isSearchable())->toBeTrue()
        ->and($column->isGloballySearchable())->toBeTrue()
        ->and($column->isIndividuallySearchable())->toBeFalse();
});

it('evaluates a searchable closure as the condition, not as a search column', function (): void {
    expect(TextColumn::make('title')->searchable(fn (): bool => false)->isSearchable())->toBeFalse()
        ->and(TextColumn::make('title')->searchable(fn (): bool => true)->isGloballySearchable())->toBeTrue();
});

it('takes search-column strings while staying searchable', function (): void {
    expect(TextColumn::make('title')->searchable('title')->isSearchable())->toBeTrue()
        ->and(TextColumn::make('title')->searchable(['title', 'subtitle'])->isSearchable())->toBeTrue();
});

it('scopes searchability with the isGlobal and isIndividual flags', function (): void {
    $individual = TextColumn::make('title')->searchable(isIndividual: true, isGlobal: false);

    expect($individual->isSearchable())->toBeTrue()
        ->and($individual->isGloballySearchable())->toBeFalse()
        ->and($individual->isIndividuallySearchable())->toBeTrue();
});

it('defers term splitting to the table until the column decides for itself', function (): void {
    expect(TextColumn::make('title')->shouldSplitIndividualSearchTerms())->toBeNull()
        ->and(TextColumn::make('title')->splitIndividualSearchTerms()->shouldSplitIndividualSearchTerms())->toBeTrue()
        ->and(TextColumn::make('title')->splitIndividualSearchTerms(false)->shouldSplitIndividualSearchTerms())->toBeFalse()
        ->and(TextColumn::make('title')->splitIndividualSearchTerms(fn (): bool => false)->shouldSplitIndividualSearchTerms())->toBeFalse();
});

it('refuses an unmounted column by name rather than raising an uninitialised property error', function (): void {
    expect(fn (): Table => Column::make('title')->getTable())
        ->toThrow(LogicException::class, 'The column [title] is not mounted to a table.');
});

it('kebab-cases each dotted segment of the name for the cell and header classes', function (): void {
    expect(Column::make('title')->getCellClassName())->toBe('sy-ta-cell-title')
        ->and(Column::make('releasedAt')->getCellClassName())->toBe('sy-ta-cell-released-at')
        ->and(Column::make('author.fullName')->getCellClassName())->toBe('sy-ta-cell-author.full-name')
        ->and(Column::make('authorFullName')->getCellClassName())->toBe('sy-ta-cell-author-full-name')
        ->and(Column::make('releasedAt')->getHeaderCellClassName())->toBe('sy-ta-header-cell-released-at');
});

it('hands a closure the row loop it was given', function (): void {
    $column = Column::make('title')->rowLoop(['index0' => 1, 'index' => 2, 'length' => 3, 'first' => false, 'last' => false, 'revindex0' => 1]);

    expect($column->extraCellAttributes(fn (?stdClass $rowLoop): array => [
        'data-iteration' => $rowLoop?->iteration,
        'data-index' => $rowLoop?->index,
        'data-remaining' => $rowLoop?->remaining,
        'data-count' => $rowLoop?->count,
    ])->getExtraCellAttributes())->toBe([
        'data-iteration' => 2,
        'data-index' => 1,
        'data-remaining' => 1,
        'data-count' => 3,
    ]);
});

it('falls back to the default state when the read is blank', function (): void {
    expect(Column::make('note')->record(['note' => null])->default('—')->getState())->toBe('—')
        ->and(Column::make('note')->record(['note' => ''])->default('—')->getState())->toBe('—')
        ->and(Column::make('note')->record(['note' => []])->default('—')->getState())->toBe('—')
        ->and(Column::make('note')->record(['note' => 'kept'])->default('—')->getState())->toBe('kept');
});

it('keeps a falsy but filled state rather than defaulting it', function (): void {
    expect(Column::make('plays')->record(['plays' => 0])->default(99)->getState())->toBe(0)
        ->and(Column::make('flag')->record(['flag' => false])->default(true)->getState())->toBeFalse();
});

it('evaluates a default-state closure', function (): void {
    expect(Column::make('note')->record(['note' => null])->default(fn (): string => 'computed')->getState())->toBe('computed')
        ->and(Column::make('note')->record(['note' => null])->getDefaultState())->toBeNull();
});

it('caches the defaulted state per record key', function (): void {
    $calls = 0;
    $column = Column::make('note')->record(['note' => null])->recordKey('1')->default(function () use (&$calls): string {
        $calls++;

        return 'computed';
    });

    expect($column->getState())->toBe('computed')
        ->and($column->getState())->toBe('computed')
        ->and($calls)->toBe(1);
});

it('splits a string state on the separator', function (): void {
    expect(Column::make('tags')->record(['tags' => 'symfony,forms'])->separator()->getState())->toBe(['symfony', 'forms'])
        ->and(Column::make('tags')->record(['tags' => 'symfony|forms'])->separator('|')->getState())->toBe(['symfony', 'forms'])
        ->and(Column::make('tags')->record(['tags' => 'symfony,forms'])->getState())->toBe('symfony,forms');
});

it('collapses a single blank part to an empty list, which the default then fills', function (): void {
    expect(Column::make('tags')->record(['tags' => ''])->separator()->getState())->toBeNull()
        ->and(Column::make('tags')->record(['tags' => '   '])->separator()->getState())->toBeNull()
        ->and(Column::make('tags')->record(['tags' => ''])->separator()->default(['none'])->getState())->toBe(['none']);
});

it('leaves a non-string state untouched by the separator', function (): void {
    expect(Column::make('tags')->record(['tags' => ['symfony', 'forms']])->separator()->getState())->toBe(['symfony', 'forms'])
        ->and(Column::make('plays')->record(['plays' => 12])->separator()->getState())->toBe(12);
});

it('evaluates a separator closure and skips splitting for a falsy one', function (): void {
    expect(Column::make('tags')->record(['tags' => 'a;b'])->separator(fn (): string => ';')->getState())->toBe(['a', 'b'])
        ->and(Column::make('tags')->record(['tags' => 'a,b'])->separator(null)->getState())->toBe('a,b')
        ->and(Column::make('tags')->record(['tags' => 'a,b'])->separator('')->getState())->toBe('a,b')
        ->and(Column::make('tags')->separator('|')->getSeparator())->toBe('|');
});
