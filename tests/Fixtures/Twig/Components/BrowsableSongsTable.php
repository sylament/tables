<?php

declare(strict_types=1);

namespace Sylament\Tables\Tests\Fixtures\Twig\Components;

use Sylament\Support\Pagination\Paginator;
use Sylament\Tables\Columns\TextColumn;
use Sylament\Tables\Concerns\HasTable;
use Sylament\Tables\Contracts\InteractsWithTable;
use Sylament\Tables\Table;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/**
 * A host whose table is sortable, searchable and paginated at once — the shape
 * the sort headers, the search toolbar and the pagination footer all render
 * against in a single pass.
 */
#[AsTwigComponent(name: 'BrowsableSongsTable', template: '@TablesFixtures/components/BrowsableSongsTable.html.twig')]
class BrowsableSongsTable implements InteractsWithTable
{
    use HasTable;

    public function table(Table $table): Table
    {
        return $table
            ->records(function (int $page, int|string|null $recordsPerPage): Paginator {
                $rows = array_map(
                    static fn (int $i): array => ['title' => sprintf('Song %02d', $i)],
                    range(1, 43),
                );

                assert(is_int($recordsPerPage));

                return new Paginator(
                    records: array_slice($rows, ($page - 1) * $recordsPerPage, $recordsPerPage),
                    totalRecordCount: count($rows),
                    page: $page,
                    recordsPerPage: $recordsPerPage,
                );
            })
            ->columns([
                TextColumn::make('title')->sortable()->searchable(),
                TextColumn::make('album'),
            ])
            ->paginationPageOptions([5, 10, 'all'])
            ->defaultPaginationPageOption(5);
    }
}
