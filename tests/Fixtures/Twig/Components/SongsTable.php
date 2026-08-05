<?php

declare(strict_types=1);

namespace Sylament\Tables\Tests\Fixtures\Twig\Components;

use Sylament\Tables\Columns\TextColumn;
use Sylament\Tables\Concerns\HasTable;
use Sylament\Tables\Contracts\InteractsWithTable;
use Sylament\Tables\Table;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/**
 * Exercises the `renderTable()` Twig function from a real host component: its
 * template calls the function with no arguments and the extension resolves the
 * table off `this`.
 */
#[AsTwigComponent(name: 'SongsTable', template: '@TablesFixtures/components/SongsTable.html.twig')]
class SongsTable implements InteractsWithTable
{
    use HasTable;

    public function table(Table $table): Table
    {
        return $table
            ->records(fn (): array => [['title' => 'Karma Police']])
            ->columns([TextColumn::make('title')]);
    }
}
