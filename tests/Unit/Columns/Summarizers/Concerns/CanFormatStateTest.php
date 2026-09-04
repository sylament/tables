<?php

declare(strict_types=1);

use Sylament\Tables\Columns\Summarizers\Summarizer;
use Sylament\Tables\Columns\TextColumn;
use Sylament\Tables\Table;
use Sylament\Tables\Tests\Fixtures\Concerns\TableHost;
use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;
use Twig\Markup;

/**
 * A summarizer on a column of a table naming an explicit number locale, so the intl
 * output does not move with the machine's.
 */
function summarizerFormatting(string $locale = 'en_US'): Summarizer
{
    return Summarizer::make()->column(TextColumn::make('plays')->table(Table::make(new TableHost())->defaultNumberLocale($locale)));
}

/**
 * A summarizer carrying the sanitizer the container injects — `html()` and `markdown()`
 * refuse to run without one.
 */
function summarizerSanitizing(): Summarizer
{
    $summarizer = summarizerFormatting();
    $summarizer->setHtmlSanitizer(new HtmlSanitizer(new HtmlSanitizerConfig()->allowSafeElements()));

    return $summarizer;
}

it('formats a state through a formatStateUsing closure', function (): void {
    expect(summarizerFormatting()->formatStateUsing(fn (mixed $state): string => "≈{$state}")->formatState(10))->toBe('≈10');
});

it('limits characters and appends the end only when longer', function (): void {
    expect(summarizerFormatting()->limit(5)->formatState('abcdefghij'))->toBe('abcde...')
        ->and(summarizerFormatting()->limit(5)->formatState('abc'))->toBe('abc')
        ->and(summarizerFormatting()->limit(5, '…')->formatState('abcdefghij'))->toBe('abcde…');
});

it('reads a placeholder for a blank state and nothing for a filled one', function (): void {
    expect(summarizerFormatting()->placeholder('—')->formatState(null))->toBe('—')
        ->and(summarizerFormatting()->placeholder('—')->formatState(''))->toBe('—')
        ->and(summarizerFormatting()->placeholder(fn (): string => 'none')->formatState(null))->toBe('none')
        ->and(summarizerFormatting()->placeholder('—')->formatState(0))->toBe('0')
        ->and(summarizerFormatting()->formatState(null))->toBeNull();
});

it('affixes a prefix and a suffix', function (): void {
    expect(summarizerFormatting()->prefix('$')->suffix(' total')->formatState(10))->toBe('$10 total')
        ->and(summarizerFormatting()->prefix(fn (): string => '~')->formatState(10))->toBe('~10');
});

/*
 * A markup affix turns the whole value into markup, so the state beside it has to be
 * escaped by hand — nothing downstream escapes what a template renders raw.
 */
it('escapes a plain state once a markup affix makes the value markup', function (): void {
    $formatted = summarizerFormatting()->suffix(new Markup('<b>!</b>', 'UTF-8'))->formatState('<script>');

    expect($formatted)->toBeInstanceOf(Markup::class)
        ->and((string) $formatted)->toBe('&lt;script&gt;<b>!</b>');
});

it('formats money in the table currency and locale', function (): void {
    expect(summarizerFormatting()->money()->formatState(1234.5))->toBe('$1,234.50')
        ->and(summarizerFormatting()->money('eur', locale: 'de_DE')->formatState(1234.5))->toBe("1.234,50\u{a0}€")
        ->and(summarizerFormatting()->money(divideBy: 100)->formatState(1000))->toBe('$10.00')
        ->and(summarizerFormatting()->money()->formatState('n/a'))->toBe('n/a')
        ->and(summarizerFormatting()->money()->formatState(null))->toBeNull();
});

it('formats numerics through the intl decimal formatter', function (): void {
    expect(summarizerFormatting()->numeric()->formatState(1234.5678))->toBe('1,234.568')
        ->and(summarizerFormatting()->numeric(decimalPlaces: 2)->formatState(1234.5678))->toBe('1,234.57')
        ->and(summarizerFormatting()->numeric(decimalPlaces: 2, decimalSeparator: ',', thousandsSeparator: ' ')->formatState(1234.5678))->toBe('1 234,57')
        ->and(summarizerFormatting('de_DE')->numeric()->formatState(1000))->toBe('1.000')
        ->and(summarizerFormatting()->numeric()->formatState('n/a'))->toBe('n/a');
});

it('sanitizes an html state and keeps it out of the truncation', function (): void {
    $formatted = summarizerSanitizing()->html()->limit(4)->formatState('<b>bold</b><script>alert(1)</script>');

    expect($formatted)->toBeInstanceOf(Markup::class)
        ->and((string) $formatted)->toBe('<b>bold</b>');
});

it('converts markdown before sanitizing it', function (): void {
    expect((string) summarizerSanitizing()->markdown()->formatState('**bold** <script>alert(1)</script>'))
        ->toContain('<strong>bold</strong>')
        ->not->toContain('<script>');
});

it('is html whenever it is markdown', function (): void {
    expect(summarizerFormatting()->isHtml())->toBeFalse()
        ->and(summarizerFormatting()->html()->isHtml())->toBeTrue()
        ->and(summarizerFormatting()->html(fn (): bool => false)->isHtml())->toBeFalse()
        ->and(summarizerFormatting()->markdown()->isHtml())->toBeTrue()
        ->and(summarizerFormatting()->markdown()->isMarkdown())->toBeTrue();
});

it('refuses to render html without the sanitizer the container injects', function (): void {
    expect(fn (): mixed => summarizerFormatting()->html()->formatState('<b>bold</b>'))
        ->toThrow(LogicException::class, 'has no HTML sanitizer');
});

it('reads the affixes back', function (): void {
    expect(summarizerFormatting()->prefix('$')->getPrefix())->toBe('$')
        ->and(summarizerFormatting()->suffix(fn (): string => '%')->getSuffix())->toBe('%')
        ->and(summarizerFormatting()->getPrefix())->toBeNull();
});

/*
 * A range hands back a pair and a list summary a list, and each is rendered by its own
 * template — item by item, in the list's case — so the array passes through whole.
 */
it('passes an array state through for the templates that render one', function (): void {
    expect(summarizerFormatting()->formatState([10, 20]))->toBe([10, 20])
        ->and(summarizerFormatting()->formatStateUsing(fn (array $state): array => array_reverse($state))->formatState([10, 20]))->toBe([20, 10]);
});
