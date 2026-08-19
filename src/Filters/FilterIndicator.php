<?php

declare(strict_types=1);

namespace Sylament\Tables\Filters;

final readonly class FilterIndicator
{
    /**
     * @param array<string, string|null> $removeArguments
     */
    public function __construct(
        public Indicator $indicator,
        public string $removeAction,
        public array $removeArguments,
    ) {
    }
}
