<?php

declare(strict_types=1);

namespace Sylament\Tables\Testing;

use Sylament\Actions\Testing\Testable as BaseTestable;

class Testable extends BaseTestable
{
    use TestsBulkActions;
    use TestsColumns;
    use TestsFilters;
    use TestsRecords;
}
