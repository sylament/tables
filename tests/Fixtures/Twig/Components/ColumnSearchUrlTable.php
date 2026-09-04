<?php

declare(strict_types=1);

namespace Sylament\Tables\Tests\Fixtures\Twig\Components;

use Sylament\Tables\Columns\TextColumn;
use Sylament\Tables\Table;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Metadata\UrlMapping;

/**
 * The page-shaped per-column-search host: it binds the bag to the query string
 * and persists it in the session, and it is the *only* host on the browsing
 * page that maps `columnSearches` — so a `?columnSearches[title]=` seeds this
 * table and leaves its neighbours' bags at their own seed.
 */
#[AsLiveComponent(name: 'ColumnSearchUrlTable', template: '@TablesFixtures/components/ColumnSearchUrlTable.html.twig')]
final class ColumnSearchUrlTable extends BrowsingStateTable
{
    public function table(Table $table): Table
    {
        return parent::table($table)
            ->columns([
                TextColumn::make('title')->searchable(isIndividual: true),
                TextColumn::make('plays')->sortable(),
            ])
            ->persistColumnSearchesInSession();
    }

    protected function getTableUrlMapping(string $prop): ?UrlMapping
    {
        return $prop === 'columnSearches' ? new UrlMapping(as: 'columnSearches') : null;
    }
}
