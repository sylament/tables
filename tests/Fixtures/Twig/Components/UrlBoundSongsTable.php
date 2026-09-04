<?php

declare(strict_types=1);

namespace Sylament\Tables\Tests\Fixtures\Twig\Components;

use Sylament\Tables\Table;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Metadata\UrlMapping;

/**
 * The page-shaped host: it binds all five state props to the query string under
 * Filament's own names and persists sort, search and filters in the session —
 * the opt-in a routed panel page makes.
 */
#[AsLiveComponent(name: 'UrlBoundSongsTable', template: '@TablesFixtures/components/UrlBoundSongsTable.html.twig')]
final class UrlBoundSongsTable extends BrowsingStateTable
{
    public function table(Table $table): Table
    {
        return parent::table($table)
            ->persistSortInSession()
            ->persistSearchInSession()
            ->persistFiltersInSession();
    }

    protected function getTableUrlMapping(string $prop): ?UrlMapping
    {
        return match ($prop) {
            'page', 'sort', 'search', 'filters', 'grouping' => new UrlMapping(as: $prop),
            default => null,
        };
    }
}
