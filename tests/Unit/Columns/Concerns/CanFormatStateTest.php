<?php

declare(strict_types=1);

use Sylament\Support\Contracts\HasLabel;
use Sylament\Tables\Columns\TextColumn;
use Sylament\Tables\Tests\Fixtures\Records\Format;
use Sylament\Tables\Tests\Fixtures\Records\Status;
use Twig\Markup;

it('json encodes an array state', function (): void {
    expect(TextColumn::make('meta')->formatState(['a' => 1]))->toBe('{"a":1}');
});

it('unwraps a state implementing the label contract', function (): void {
    expect(TextColumn::make('status')->formatState(Status::Published))->toBe('Published');
});

it('unwraps a backed enum that labels itself no other way', function (): void {
    expect(TextColumn::make('format')->formatState(Format::Vinyl))->toBe('vinyl');
});

it('re-reads the markup flag off the unwrapped label', function (): void {
    $state = new class implements HasLabel {
        public function getLabel(): Markup
        {
            return new Markup('<b>abcdefghij</b>', 'UTF-8');
        }
    };

    $result = TextColumn::make('title')->limit(3)->formatState($state);

    expect($result)->toBeInstanceOf(Markup::class)
        ->and((string) $result)->toBe('<b>abcdefghij</b>');
});

it('limits characters and appends the end only when longer', function (): void {
    expect(TextColumn::make('title')->limit(5)->formatState('abcdefghij'))->toBe('abcde...')
        ->and(TextColumn::make('title')->limit(5)->formatState('abc'))->toBe('abc')
        ->and(TextColumn::make('title')->limit(5, '…')->formatState('abcdefghij'))->toBe('abcde…');
});

it('trims trailing whitespace before appending the limit end', function (): void {
    expect(TextColumn::make('title')->limit(3)->formatState('ab cdef'))->toBe('ab...');
});

it('limits words and appends the end only when longer', function (): void {
    expect(TextColumn::make('title')->words(2)->formatState('one two three four'))->toBe('one two...')
        ->and(TextColumn::make('title')->words(5)->formatState('one two'))->toBe('one two');
});

/*
 * Zero is the falsy limit both truncations test for, and the two fail differently without
 * that test: a zero character limit slices the state away and returns the ellipsis alone,
 * where a zero word limit interpolates `{1,0}` into the quantifier and makes `preg_match()`
 * raise — silent here, an `\ErrorException` under a debug kernel's error handler. The
 * handler is installed for the word case because the raise is the whole failure: the
 * short-circuited `preg_match()` returns `false`, so the state comes back untruncated
 * either way and the string assertion alone cannot tell the two apart.
 */
it('treats a zero character limit as no truncation', function (): void {
    expect(TextColumn::make('title')->limit(0)->formatState('abcdefghij'))->toBe('abcdefghij');
});

it('treats a zero word limit as no truncation, and raises nothing doing it', function (): void {
    $raised = [];

    set_error_handler(function (int $severity, string $message) use (&$raised): bool {
        $raised[] = $message;

        return true;
    });

    try {
        $state = TextColumn::make('title')->words(0)->formatState('one two three');
    } finally {
        restore_error_handler();
    }

    expect($state)->toBe('one two three')
        ->and($raised)->toBe([]);
});

it('truncates before affixing, so the limit measures the state alone', function (): void {
    expect(TextColumn::make('title')->prefix('# ')->limit(5)->formatState('abcdefghij'))->toBe('# abcde...');
});

it('applies the character limit before the word limit', function (): void {
    expect(TextColumn::make('title')->limit(9)->words(2)->formatState('one two three four'))->toBe('one two...');
});

it('concatenates a prefix and a suffix', function (): void {
    expect(TextColumn::make('title')->prefix('# ')->suffix(' !')->formatState('Airbag'))->toBe('# Airbag !');
});

it('escapes plain state when an affix is markup and returns markup', function (): void {
    $result = TextColumn::make('title')->prefix(new Markup('<b>#</b>', 'UTF-8'))->formatState('a<b');

    expect($result)->toBeInstanceOf(Markup::class)
        ->and((string) $result)->toBe('<b>#</b>a&lt;b');
});

it('escapes a plain affix when the state is markup', function (): void {
    $result = TextColumn::make('title')
        ->formatStateUsing(fn (): Markup => new Markup('<b>hi</b>', 'UTF-8'))
        ->suffix(' <x>')
        ->formatState('ignored');

    expect($result)->toBeInstanceOf(Markup::class)
        ->and((string) $result)->toBe('<b>hi</b> &lt;x&gt;');
});

it('skips truncation for a markup state', function (): void {
    $result = TextColumn::make('title')
        ->formatStateUsing(fn (): Markup => new Markup('<b>abcdefghij</b>', 'UTF-8'))
        ->limit(3)
        ->formatState('ignored');

    expect($result)->toBeInstanceOf(Markup::class)
        ->and((string) $result)->toBe('<b>abcdefghij</b>');
});

it('applies the format callback before the transforms', function (): void {
    expect(TextColumn::make('title')->formatStateUsing(fn (string $state): string => $state . $state)->limit(3)->formatState('airbag'))
        ->toBe('air...');
});
