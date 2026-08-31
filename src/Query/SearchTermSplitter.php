<?php

declare(strict_types=1);

namespace Sylament\Tables\Query;

use function Sylament\Support\filled;

final class SearchTermSplitter
{
    /**
     * @return list<string>
     */
    public static function split(string $search): array
    {
        $normalized = preg_replace('/(\s|\x{3164}|\x{1160})+/u', ' ', $search) ?? $search;

        return array_values(array_filter(
            str_getcsv($normalized, separator: ' ', escape: '\\'),
            filled(...),
        ));
    }
}
