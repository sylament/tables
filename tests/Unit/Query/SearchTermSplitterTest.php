<?php

declare(strict_types=1);

use Sylament\Tables\Query\SearchTermSplitter;

it('splits on whitespace and drops blanks', function (): void {
    expect(SearchTermSplitter::split('  alpha   beta '))->toBe(['alpha', 'beta']);
});

it('keeps a double-quoted phrase as one word', function (): void {
    expect(SearchTermSplitter::split('a "b c"'))->toBe(['a', 'b c']);
});

it('normalizes Hangul filler characters to spaces', function (): void {
    expect(SearchTermSplitter::split("alpha\u{3164}beta"))->toBe(['alpha', 'beta']);
});

it('returns an empty list for a blank string', function (): void {
    expect(SearchTermSplitter::split('   '))->toBe([]);
});
