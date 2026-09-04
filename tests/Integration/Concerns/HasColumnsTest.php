<?php

declare(strict_types=1);

namespace Sylament\Tables\Tests\Integration\Concerns;

use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use Sylament\Tables\Tests\Fixtures\Doctrine\Entity\Song;
use Sylament\Tables\Tests\Fixtures\Records\Format;
use Sylament\Tables\Tests\Fixtures\Twig\Components\ArrayRecordActionsTable;
use Sylament\Tables\Tests\Fixtures\Twig\Components\ColumnActionsTable;
use Sylament\Tables\Tests\Fixtures\Twig\Components\EditableSongsTable;
use Sylament\Tables\Tests\Fixtures\Twig\Components\TypeRefusalSongsTable;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;
use Symfony\UX\LiveComponent\Test\TestLiveComponent;

use function Sylament\Tables\Tests\seededGenresClient;

uses(InteractsWithLiveComponents::class);

/*
 * The host's column-action live action: what it binds before evaluating a
 * column's closure, and every arm it refuses without effect. The fixture's
 * columns record each call on a `LiveProp`, so what the closure was handed
 * survives the round trip and is readable off the re-hydrated host.
 */

/**
 * @param array<string, mixed> $arguments
 * @return list<string>
 */
function columnActionCalls(array $arguments): array
{
    $host = test()->createLiveComponent(ColumnActionsTable::class)
        ->call('callTableColumnAction', $arguments)
        ->component();

    assert($host instanceof ColumnActionsTable);

    return $host->calls;
}

it('binds the record and its key before evaluating the column closure', function (): void {
    expect(columnActionCalls(['name' => 'title', 'recordKey' => '2']))->toBe(['closure:Let Down:2']);
});

it('binds an array record before evaluating a string action', function (): void {
    $host = test()->createLiveComponent(ArrayRecordActionsTable::class)
        ->call('callTableColumnAction', ['name' => 'title', 'recordKey' => '2'])
        ->component();

    assert($host instanceof ArrayRecordActionsTable);

    expect($host->calls)->toBe(['array:Let Down']);
});

it('refuses a record key the table does not carry', function (): void {
    expect(columnActionCalls(['name' => 'title', 'recordKey' => '99']))->toBe([]);
});

it('refuses a column the table does not declare', function (): void {
    expect(columnActionCalls(['name' => 'nonesuch', 'recordKey' => '1']))->toBe([]);
});

it('refuses a column that carries no action at all', function (): void {
    expect(columnActionCalls(['name' => 'id', 'recordKey' => '1']))->toBe([]);
});

it('evaluates the closure a string action name was wrapped into', function (): void {
    expect(columnActionCalls(['name' => 'album', 'recordKey' => '1']))->toBe(['string:Airbag']);
});

it('refuses a hidden column, whose closure a rendered page never offered', function (): void {
    expect(columnActionCalls(['name' => 'secret', 'recordKey' => '1']))->toBe([]);
});

it('leaves a column the user toggled hidden reachable, because the gate is not their preference', function (): void {
    $component = test()->createLiveComponent(ColumnActionsTable::class);
    $mounted = $component->component();

    assert($mounted instanceof ColumnActionsTable);

    expect($mounted->getTable()->getColumn('toggled')?->isToggledHidden())->toBeTrue();

    $host = $component->call('callTableColumnAction', ['name' => 'toggled', 'recordKey' => '1'])->component();

    assert($host instanceof ColumnActionsTable);

    expect($host->calls)->toBe(['toggled:Airbag']);
});

it('resolves both refusal and call off the table a column reorder re-columned', function (): void {
    $component = test()->createLiveComponent(ColumnActionsTable::class);

    $component->call('applyTableColumnManager', ['state' => array_reverse($component->component()->tableColumns)]);

    $refused = $component->call('callTableColumnAction', ['name' => 'secret', 'recordKey' => '1'])->component();

    assert($refused instanceof ColumnActionsTable);

    expect(array_column($refused->tableColumns, 'name'))->toBe(['toggled', 'secret', 'id', 'actionObject', 'album', 'title'])
        ->and($refused->calls)->toBe([]);

    $called = $component->call('callTableColumnAction', ['name' => 'title', 'recordKey' => '2'])->component();

    assert($called instanceof ColumnActionsTable);

    expect($called->calls)->toBe(['closure:Let Down:2']);
});

it('refuses a column whose action is an Action object', function (): void {
    expect(columnActionCalls(['name' => 'actionObject', 'recordKey' => '1']))->toBe([]);
});

it('leaves the column unbound when its action is an Action object', function (): void {
    $host = $this::getContainer()->get(ColumnActionsTable::class);

    assert($host instanceof ColumnActionsTable);

    $host->callTableColumnAction('actionObject', '1');

    $column = $host->getTable()->getColumn('actionObject');

    expect($host->calls)->toBe([])
        ->and($column?->getRecord())->toBeNull()
        ->and($column?->getRecordKey())->toBeNull();
});

/*
 * The host's write-back live action, over a real database. Every outcome is read
 * off the container's entity manager and off the re-render — never off
 * `->component()`, which re-hydrates a fresh host and would make a refusal pass
 * whatever the write did.
 */

/**
 * The editable fixture over songs 1, 2 and 3, with song 4 seeded outside the
 * table's own query so a key the database holds but the table cannot reach has
 * something to point at.
 *
 * @return array{TestLiveComponent, EntityManagerInterface}
 */
function editableSongsRig(): array
{
    [$client, $entityManager] = seededGenresClient();

    $entityManager->persist(new Song(3, 'Exit Music', 300));
    $entityManager->persist(new Song(4, 'Hidden Track', 5000));
    $entityManager->flush();

    return [test()->createLiveComponent(EditableSongsTable::class, client: $client), $entityManager];
}

function writeTableCell(TestLiveComponent $component, string $column, string $record, mixed $input): TestLiveComponent
{
    return $component->call('updateTableColumnState', ['column' => $column, 'record' => $record, 'input' => $input]);
}

function refreshedSong(EntityManagerInterface $entityManager, int $id): Song
{
    $song = $entityManager->find(Song::class, $id);

    assert($song instanceof Song);

    $entityManager->refresh($song);

    return $song;
}

it('writes the posted state onto the record and shows it in the same re-render', function (): void {
    [$component, $entityManager] = editableSongsRig();

    $html = (string) writeTableCell($component, 'title', '1', 'Bones')->render();

    expect(refreshedSong($entityManager, 1)->getTitle())->toBe('Bones')
        ->and($html)->toContain('value="Bones"')
        ->and($html)->toContain('data-sylament--tables--editable-cell-state-value="Bones"');
});

it('writes a boolean toggle through to the record', function (): void {
    [$component, $entityManager] = editableSongsRig();

    writeTableCell($component, 'isFeatured', '1', true);

    expect(refreshedSong($entityManager, 1)->isFeatured())->toBeTrue();
});

it('writes a false checkbox rather than reading it as blank', function (): void {
    [$component, $entityManager] = editableSongsRig();

    writeTableCell($component, 'isExplicit', '1', true);
    writeTableCell($component, 'isExplicit', '1', false);

    expect(refreshedSong($entityManager, 1)->getIsExplicit())->toBeFalse();
});

it('accepts a null input, which the live request drops rather than carrying', function (): void {
    [$component, $entityManager] = editableSongsRig();

    writeTableCell($component, 'mood', '1', 'calm');
    writeTableCell($component, 'mood', '1', null);

    expect(refreshedSong($entityManager, 1)->getMood())->toBeNull();
});

it('paints a refused input under the control, echoed back and carried as the cell state', function (): void {
    [$component, $entityManager] = editableSongsRig();

    $html = (string) writeTableCell($component, 'title', '1', 'ab')->render();

    expect(refreshedSong($entityManager, 1)->getTitle())->toBe('Airbag')
        ->and($html)->toContain('sy-ta-cell-error')
        ->and($html)->toContain('too short')
        ->and($html)->toContain('value="ab"')
        ->and($html)->toContain('data-sylament--tables--editable-cell-state-value="ab"')
        ->and($html)->toContain('aria-invalid="true"');
});

it('leaves the next request clean, because the error bag lives for one request', function (): void {
    [$component] = editableSongsRig();

    $refused = (string) writeTableCell($component, 'title', '1', 'ab')->render();
    $clean = (string) writeTableCell($component, 'title', '1', 'Bones')->render();

    expect($refused)->toContain('sy-ta-cell-error')
        ->and($clean)->not->toContain('sy-ta-cell-error');
});

it('refuses a blank against a record type that rejects null', function (): void {
    [$component, $entityManager] = editableSongsRig();

    $html = (string) writeTableCell($component, 'tempo', '1', '')->render();

    expect(refreshedSong($entityManager, 1)->getTempo())->toBe('medium')
        ->and($html)->toContain('sy-ta-cell-error');
});

it('refuses an option the column disabled', function (): void {
    [$component, $entityManager] = editableSongsRig();

    $html = (string) writeTableCell($component, 'mood', '1', 'wild')->render();

    expect(refreshedSong($entityManager, 1)->getMood())->toBeNull()
        ->and($html)->toContain('sy-ta-cell-error');
});

it('keeps a refused option selected, because the rejected input is the state the partial compares', function (): void {
    [$component] = editableSongsRig();

    $html = (string) writeTableCell($component, 'mood', '1', 'wild')->render();
    $cell = new Crawler($html)->filter('div.sy-ta-select[data-sylament--tables--editable-cell-state-value="wild"]');

    expect($cell->filter('option[selected]')->attr('value'))->toBe('wild');
});

it('refuses a column the table does not declare, without effect', function (): void {
    [$component, $entityManager] = editableSongsRig();

    $html = (string) writeTableCell($component, 'nonesuch', '1', 'Bones')->render();

    expect(refreshedSong($entityManager, 1)->getTitle())->toBe('Airbag')
        ->and($html)->not->toContain('sy-ta-cell-error');
});

it('refuses a write to a hidden column, without effect and without a refusal to paint', function (): void {
    [$component, $entityManager] = editableSongsRig();

    $html = (string) writeTableCell($component, 'secretTitle', '1', 'Bones')->render();

    expect(refreshedSong($entityManager, 1)->getTitle())->toBe('Airbag')
        ->and($html)->not->toContain('sy-ta-cell-error');
});

it('writes to a column the user toggled hidden, because the gate is not their preference', function (): void {
    [$component, $entityManager] = editableSongsRig();

    $host = $component->component();

    assert($host instanceof EditableSongsTable);

    expect($host->getTable()->getColumn('toggledTitle')?->isToggledHidden())->toBeTrue();

    writeTableCell($component, 'toggledTitle', '1', 'Bones');

    expect(refreshedSong($entityManager, 1)->getTitle())->toBe('Bones');
});

it('refuses a column that is not editable, without effect', function (): void {
    [$component, $entityManager] = editableSongsRig();

    writeTableCell($component, 'plays', '1', '999');

    expect(refreshedSong($entityManager, 1)->getPlays())->toBe(100);
});

it('refuses a record key the table does not carry, without effect', function (): void {
    [$component, $entityManager] = editableSongsRig();

    writeTableCell($component, 'title', '99', 'Bones');

    expect(refreshedSong($entityManager, 1)->getTitle())->toBe('Airbag');
});

it('refuses a record the table query filters out, without effect', function (): void {
    [$component, $entityManager] = editableSongsRig();

    writeTableCell($component, 'title', '4', 'Bones');

    expect(refreshedSong($entityManager, 4)->getTitle())->toBe('Hidden Track');
});

it('refuses a write to a cell the column disabled for that row', function (): void {
    [$component, $entityManager] = editableSongsRig();

    writeTableCell($component, 'isFeatured', '3', true);
    writeTableCell($component, 'isFeatured', '2', true);

    expect(refreshedSong($entityManager, 3)->isFeatured())->toBeNull()
        ->and(refreshedSong($entityManager, 2)->isFeatured())->toBeTrue();
});

it('binds the record and its key onto the column before it judges or writes the cell', function (): void {
    seededGenresClient();

    $host = $this::getContainer()->get(EditableSongsTable::class);

    assert($host instanceof EditableSongsTable);

    $host->updateTableColumnState('title', '2', 'Bones');

    $column = $host->getTable()->getColumn('title');

    expect($column?->getRecord())->toBeInstanceOf(Song::class)
        ->and($column?->getRecordKey())->toBe('2');
});

it('clears the cell state a hook cached before the write, so the next read is not stale', function (): void {
    seededGenresClient();

    $host = $this::getContainer()->get(EditableSongsTable::class);

    assert($host instanceof EditableSongsTable);

    $host->updateTableColumnState('title', '1', 'Bones');

    expect($host->getTable()->getColumn('title')?->getState())->toBe('Bones');
});

it('casts a posted option onto an enum-typed property and marks it selected in the same re-render', function (): void {
    [$component, $entityManager] = editableSongsRig();

    $html = (string) writeTableCell($component, 'format', '1', 'vinyl')->render();
    $cell = new Crawler($html)->filter('div.sy-ta-select[data-sylament--tables--editable-cell-state-value="vinyl"]');

    expect(refreshedSong($entityManager, 1)->getFormat())->toBe(Format::Vinyl)
        ->and($cell->filter('option[selected]')->attr('value'))->toBe('vinyl');
});

it('runs the after hook on the override arm, over an integer-keyed option map', function (): void {
    [$component, $entityManager] = editableSongsRig();

    writeTableCell($component, 'position', '1', '2');

    expect(refreshedSong($entityManager, 1)->getPosition())->toBe(102);
});

it('files a refusal against one cell only, and answers null for every other', function (): void {
    seededGenresClient();

    $host = $this::getContainer()->get(EditableSongsTable::class);

    assert($host instanceof EditableSongsTable);

    expect($host->getTableColumnStateError('title', '1'))->toBeNull();

    $host->updateTableColumnState('title', '1', 'ab');

    expect($host->getTableColumnStateError('title', '1'))->toMatchArray(['input' => 'ab'])
        ->and($host->getTableColumnStateError('title', '1')['message'] ?? null)->toContain('too short')
        ->and($host->getTableColumnStateError('title', '2'))->toBeNull()
        ->and($host->getTableColumnStateError('mood', '1'))->toBeNull();
});

/*
 * The types the record itself declares, which the column derives a `Type`
 * constraint from and the accessor enforces where it cannot. Every arm is a
 * refusal painted under the control: a wrong-typed value never leaves the live
 * action as a 500.
 */

/**
 * @return array{TestLiveComponent, EntityManagerInterface}
 */
function typeRefusalRig(): array
{
    [$client, $entityManager] = seededGenresClient();

    return [test()->createLiveComponent(TypeRefusalSongsTable::class, client: $client), $entityManager];
}

it('refuses a non-scalar input against a string record property, without a write', function (): void {
    [$component, $entityManager] = typeRefusalRig();

    $html = (string) writeTableCell($component, 'mood', '1', ['x' => 'y'])->render();

    expect(refreshedSong($entityManager, 1)->getMood())->toBeNull()
        ->and($html)->toContain('sy-ta-cell-error')
        ->and($html)->toContain('should be of type string');
});

it('refuses a non-numeric input against an integer record property', function (): void {
    [$component, $entityManager] = typeRefusalRig();

    $html = (string) writeTableCell($component, 'plays', '1', 'abc')->render();

    expect(refreshedSong($entityManager, 1)->getPlays())->toBe(100)
        ->and($html)->toContain('sy-ta-cell-error');
});

it('coerces a numeric string onto an integer record property', function (): void {
    [$component, $entityManager] = typeRefusalRig();

    writeTableCell($component, 'plays', '1', '12');

    expect(refreshedSong($entityManager, 1)->getPlays())->toBe(12);
});

it('files a write the record type refuses, where no constraint could be seeded', function (): void {
    [$component, $entityManager] = typeRefusalRig();

    $html = (string) writeTableCell($component, 'artist', '1', 'Radiohead')->render();

    expect(refreshedSong($entityManager, 1)->getArtist())->toBeNull()
        ->and($html)->toContain('sy-ta-cell-error')
        ->and($html)->toContain('cannot write to its record');
});

it('lets a throwing updateStateUsing out, because the write seam swallows only its own refusal', function (): void {
    seededGenresClient();

    $host = $this::getContainer()->get(TypeRefusalSongsTable::class);

    assert($host instanceof TypeRefusalSongsTable);

    expect(fn () => $host->updateTableColumnState('tempo', '1', 'fast'))
        ->toThrow(LogicException::class, 'The override arm refused this write.');
});
