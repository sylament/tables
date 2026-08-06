<?php

declare(strict_types=1);

namespace Sylament\Tables\Tests\Fixtures\Concerns;

use Sylament\Actions\Concerns\HasActions;
use Sylament\Actions\Contracts\InteractsWithActions;
use Sylament\Schemas\Concerns\HasSchemas;
use Sylament\Schemas\Contracts\InteractsWithSchemas;
use Sylament\Tables\Concerns\HasTable;
use Sylament\Tables\Contracts\InteractsWithTable;

/**
 * A host implementing the table and actions contracts at once — the shape a
 * table-bound action's live-component chain resolves to through its table.
 */
class TableActionsHost implements InteractsWithActions, InteractsWithSchemas, InteractsWithTable
{
    use HasActions;
    use HasSchemas;
    use HasTable;
}
