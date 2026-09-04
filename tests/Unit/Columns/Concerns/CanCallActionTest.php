<?php

declare(strict_types=1);

use Sylament\Actions\Action;
use Sylament\Tables\Columns\Column;
use Sylament\Tables\Tests\Fixtures\Records\Song;
use Sylament\Tables\Tests\Fixtures\Twig\Components\ArrayRecordActionsTable;
use Sylament\Tables\Tests\Fixtures\Twig\Components\ColumnActionsTable;

it('carries no action by default', function (): void {
    expect(Column::make('title')->getAction())->toBeNull()
        ->and(Column::make('title')->getActionKind())->toBeNull();
});

it('keeps a closure and an action instance as they were passed', function (): void {
    $closure = fn (): string => 'ran';
    $action = Action::make('view');

    expect(Column::make('title')->action($closure)->getAction())->toBe($closure)
        ->and(Column::make('title')->action($action)->getAction())->toBe($action);
});

it('names the kind of action the template has to wire', function (): void {
    expect(Column::make('title')->action(fn (): string => 'ran')->getActionKind())->toBe('closure')
        ->and(Column::make('title')->action(Action::make('view'))->getActionKind())->toBe('action')
        ->and(Column::make('title')->action('openThing')->getActionKind())->toBe('closure');
});

it('wraps a method name into a closure calling the host with the record', function (): void {
    $action = Column::make('title')->action('appendAlbumCall')->getAction();
    $host = new ColumnActionsTable();

    expect($action)->toBeInstanceOf(Closure::class);
    assert($action instanceof Closure);

    expect($action($host, new Song('Airbag', 'OK Computer', id: 1)))->toBe('called')
        ->and($host->calls)->toBe(['string:Airbag']);
});

it('hands an array record to the host method, as the records lane carries it', function (): void {
    $action = Column::make('title')->action('appendTitleCall')->getAction();
    $host = new ArrayRecordActionsTable();

    assert($action instanceof Closure);

    expect($action($host, ['title' => 'Airbag', '__key' => '1']))->toBe('called')
        ->and($host->calls)->toBe(['array:Airbag']);
});

it('calls the host method with no arguments when no record is bound', function (): void {
    $action = Column::make('title')->action('appendAlbumCall')->getAction();
    $host = new ColumnActionsTable();

    assert($action instanceof Closure);

    expect($action($host, null))->toBe('called')
        ->and($host->calls)->toBe(['string:none']);
});

it('clears the action back to null', function (): void {
    expect(Column::make('title')->action(fn (): string => 'ran')->action(null)->getAction())->toBeNull();
});
