<?php

declare(strict_types=1);

namespace Sylament\Tables\Tests\Fixtures\Twig\Components;

use Sylament\Tables\Table;

/**
 * The browsable host with the first/last page links turned on — rendered
 * through the table extension with a hand-built context rather than as a tag,
 * so it needs no component registration of its own.
 */
final class ExtremeLinksSongsTable extends BrowsableSongsTable
{
    public function table(Table $table): Table
    {
        return parent::table($table)->extremePaginationLinks();
    }
}
