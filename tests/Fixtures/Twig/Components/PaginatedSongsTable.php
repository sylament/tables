<?php

declare(strict_types=1);

namespace Sylament\Tables\Tests\Fixtures\Twig\Components;

use Sylament\Support\Pagination\Paginator;
use Sylament\Tables\Columns\TextColumn;
use Sylament\Tables\Concerns\HasTable;
use Sylament\Tables\Contracts\InteractsWithTable;
use Sylament\Tables\Table;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * A **live** host that paginates and searches at once, so the props blob it
 * dehydrates can be read against the two elements bound to it — the per-page
 * `<select>` and the search `<input>`. `BrowsableSongsTable` renders the same
 * chrome but is a plain Twig component, which emits no props blob at all.
 */
#[AsLiveComponent(name: 'PaginatedSongsTable', template: '@TablesFixtures/components/PaginatedSongsTable.html.twig')]
final class PaginatedSongsTable implements InteractsWithTable
{
    use DefaultActionTrait;
    use HasTable;

    public function table(Table $table): Table
    {
        return $table
            ->records(function (int $page, int|string|null $recordsPerPage): Paginator {
                $rows = array_map(
                    static fn (int $i): array => ['title' => sprintf('Song %02d', $i)],
                    range(1, 23),
                );

                assert(is_int($recordsPerPage));

                return new Paginator(
                    records: array_slice($rows, ($page - 1) * $recordsPerPage, $recordsPerPage),
                    totalRecordCount: count($rows),
                    page: $page,
                    recordsPerPage: $recordsPerPage,
                );
            })
            ->columns([TextColumn::make('title')->searchable()])
            ->paginated([10, 25]);
    }
}
