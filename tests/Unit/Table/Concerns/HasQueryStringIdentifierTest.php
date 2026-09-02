<?php

declare(strict_types=1);

use Sylament\Tables\Table;
use Sylament\Tables\Tests\Fixtures\Concerns\TableHost;
use Sylament\Tables\Tests\Fixtures\Concerns\UrlMappedTableHost;

it('carries no identifier until one is set', function (): void {
    expect(new TableHost()->getTable()->getQueryStringIdentifier())->toBeNull();
});

it('evaluates a closure identifier', function (): void {
    $table = new TableHost(fn (Table $table): Table => $table->queryStringIdentifier(fn (): string => 'songs'))->getTable();

    expect($table->getQueryStringIdentifier())->toBe('songs');
});

it('prefixes a property name with the identifier the table carries', function (): void {
    $host = new UrlMappedTableHost(configureTable: fn (Table $table): Table => $table->queryStringIdentifier('songs'));

    expect($host->getIdentifiedTableQueryStringPropertyNameFor('page'))->toBe('songsPage')
        ->and($host->getIdentifiedTableQueryStringPropertyNameFor('sort'))->toBe('songsSort');
});

it('falls back to the modifier-safe host hook when the table names no identifier', function (): void {
    $host = new UrlMappedTableHost(identifier: 'genres');

    expect($host->getIdentifiedTableQueryStringPropertyNameFor('page'))->toBe('genresPage');
});

it('lets the table setter win over the host hook', function (): void {
    $host = new UrlMappedTableHost(
        identifier: 'genres',
        configureTable: fn (Table $table): Table => $table->queryStringIdentifier('songs'),
    );

    expect($host->getIdentifiedTableQueryStringPropertyNameFor('page'))->toBe('songsPage');
});

it('leaves the property name alone when neither names an identifier', function (): void {
    expect(new TableHost()->getIdentifiedTableQueryStringPropertyNameFor('page'))->toBe('page');
});
