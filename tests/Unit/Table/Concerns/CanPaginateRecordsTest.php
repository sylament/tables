<?php

declare(strict_types=1);

use Sylament\Tables\Table;
use Sylament\Tables\Tests\Fixtures\Concerns\TableHost;

/*
 * The pagination flags a table declares. The slice the host takes from them is
 * `Unit/Concerns/CanPaginateRecordsTest.php`'s, and the page-option defaults are
 * pinned in `Unit/TableTest.php`.
 */

it('is not paginated under groupsOnly(), whatever the declaration says', function (): void {
    $paginated = new TableHost(fn (Table $table): Table => $table->paginated())->getTable();
    $groupsOnly = new TableHost(fn (Table $table): Table => $table->paginated()->groupsOnly())->getTable();

    expect($paginated->isPaginated())->toBeTrue()
        ->and($groupsOnly->isPaginated())->toBeFalse();
});
