<?php

declare(strict_types=1);

use Sylament\Tables\Enums\RecordCheckboxPosition;

it("ports both of Filament's cases and no more", function (): void {
    expect(array_column(RecordCheckboxPosition::cases(), 'name'))->toBe(['BeforeCells', 'AfterCells']);
});
