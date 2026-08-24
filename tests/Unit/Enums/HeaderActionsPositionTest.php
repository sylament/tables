<?php

declare(strict_types=1);

use Sylament\Tables\Enums\HeaderActionsPosition;

it("ports both of Filament's cases and no more", function (): void {
    expect(array_column(HeaderActionsPosition::cases(), 'name'))->toBe(['Adaptive', 'Bottom']);
});
