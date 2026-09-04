<?php

declare(strict_types=1);

use League\CommonMark\Extension\DescriptionList\DescriptionListExtension;
use League\CommonMark\Extension\Table\TableExtension;
use Sylament\Support\Contracts\HasLabel;
use Sylament\Tables\Columns\TextColumn;
use Sylament\Tables\Table;
use Sylament\Tables\Tests\Fixtures\Concerns\TableHost;
use Sylament\Tables\Tests\Fixtures\Records\EnumArgument;
use Sylament\Tables\Tests\Fixtures\Records\Format;
use Sylament\Tables\Tests\Fixtures\Records\Status;
use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;
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

/**
 * A column mounted to a table, which is where every formatting default is read from.
 */
function formattingColumn(string $name = 'value'): TextColumn
{
    return TextColumn::make($name)->table(Table::make(new TableHost()));
}

/**
 * A column mounted to a table naming an explicit number locale, so the intl output
 * does not move with the machine's.
 */
function numberColumn(string $name = 'price'): TextColumn
{
    return TextColumn::make($name)->table(Table::make(new TableHost())->defaultNumberLocale('en_US'));
}

/**
 * A column carrying the sanitizer the container would have injected — `html()` and
 * `markdown()` refuse to run without one.
 */
function sanitizingColumn(string $name = 'body'): TextColumn
{
    $column = TextColumn::make($name);
    $column->setHtmlSanitizer(new HtmlSanitizer(new HtmlSanitizerConfig()->allowSafeElements()));

    return $column;
}

it('formats a date state with the table default format', function (): void {
    expect(formattingColumn()->date()->formatState('2026-09-02 13:45:00'))->toBe('Sep 2, 2026')
        ->and(formattingColumn()->date('Y-m-d')->formatState('2026-09-02 13:45:00'))->toBe('2026-09-02')
        ->and(formattingColumn()->date(fn (): string => 'D, d M Y')->formatState('2026-09-02'))->toBe('Wed, 02 Sep 2026');
});

it('normalises a DateTimeInterface, an int timestamp and a string alike', function (): void {
    expect(formattingColumn()->date('Y-m-d H:i')->formatState(new DateTimeImmutable('2026-09-02 13:45:00', new DateTimeZone('UTC'))))->toBe('2026-09-02 13:45')
        ->and(formattingColumn()->date('Y-m-d H:i')->formatState(new DateTime('2026-09-02 13:45:00', new DateTimeZone('UTC'))))->toBe('2026-09-02 13:45')
        ->and(formattingColumn()->date('Y-m-d H:i')->formatState(1_772_113_500))->toBe('2026-02-26 13:45');
});

it('refuses a state it cannot read as a date', function (): void {
    expect(fn (): mixed => formattingColumn('publishedAt')->date()->formatState(12.5))
        ->toThrow(LogicException::class, 'State of column [publishedAt] must be a date');
});

it('formats dateTime and time from the table defaults', function (): void {
    expect(formattingColumn()->dateTime()->formatState('2026-09-02 13:45:07'))->toBe('Sep 2, 2026 13:45:07')
        ->and(formattingColumn()->time()->formatState('2026-09-02 13:45:07'))->toBe('13:45:07');
});

it('reads the same timezone default for every date arm and lets one column override it', function (): void {
    expect(formattingColumn()->getTimezone())->toBe(date_default_timezone_get())
        ->and(formattingColumn()->timezone('Europe/Prague')->getTimezone())->toBe('Europe/Prague')
        ->and(formattingColumn()->timezone(fn (): string => 'Asia/Tokyo')->getTimezone())->toBe('Asia/Tokyo')
        ->and(formattingColumn()->timezone('Europe/Prague')->dateTime('H:i')->formatState('2026-09-02 13:45:00'))->toBe('15:45')
        ->and(formattingColumn()->timezone('Europe/Prague')->time('H:i')->formatState('2026-09-02 13:45:00'))->toBe('15:45')
        ->and(formattingColumn()->dateTime('H:i', 'Asia/Tokyo')->formatState('2026-09-02 13:45:00'))->toBe('22:45');
});

it('flags which formatting family a column belongs to', function (): void {
    expect(formattingColumn()->date()->isDate())->toBeTrue()
        ->and(formattingColumn()->date()->isDateTime())->toBeFalse()
        ->and(formattingColumn()->dateTime()->isDateTime())->toBeTrue()
        ->and(formattingColumn()->time()->isTime())->toBeTrue()
        ->and(formattingColumn()->since()->isDateTime())->toBeTrue()
        ->and(formattingColumn()->money()->isMoney())->toBeTrue()
        ->and(formattingColumn()->numeric()->isNumeric())->toBeTrue()
        ->and(formattingColumn()->isDate())->toBeFalse()
        ->and(formattingColumn()->isTime())->toBeFalse()
        ->and(formattingColumn()->isMoney())->toBeFalse()
        ->and(formattingColumn()->isNumeric())->toBeFalse();
});

it('renders a relative time for since, both directions', function (): void {
    expect(formattingColumn()->since()->formatState(new DateTimeImmutable('-3 days -1 hour')))->toBe('3 days ago')
        ->and(formattingColumn()->since()->formatState(new DateTimeImmutable('+2 hours +1 minute')))->toBe('2 hours from now');
});

it('returns null for a blank state in every date arm', function (): void {
    expect(formattingColumn()->date()->formatState(null))->toBeNull()
        ->and(formattingColumn()->dateTime()->formatState(''))->toBeNull()
        ->and(formattingColumn()->time()->formatState(null))->toBeNull()
        ->and(formattingColumn()->since()->formatState(null))->toBeNull();
});

it('installs the four date tooltips without touching the displayed state', function (): void {
    $column = formattingColumn()->dateTooltip('Y-m-d');

    expect($column->getTooltip('2026-09-02 13:45:00'))->toBe('2026-09-02')
        ->and($column->formatState('2026-09-02 13:45:00'))->toBe('2026-09-02 13:45:00')
        ->and(formattingColumn()->dateTooltip()->getTooltip('2026-09-02 13:45:00'))->toBe('Sep 2, 2026')
        ->and(formattingColumn()->dateTimeTooltip()->getTooltip('2026-09-02 13:45:07'))->toBe('Sep 2, 2026 13:45:07')
        ->and(formattingColumn()->timeTooltip()->getTooltip('2026-09-02 13:45:07'))->toBe('13:45:07')
        ->and(formattingColumn()->timezone('Europe/Prague')->dateTimeTooltip('H:i')->getTooltip('2026-09-02 13:45:00'))->toBe('15:45')
        ->and(formattingColumn()->sinceTooltip()->getTooltip(new DateTimeImmutable('-3 days -1 hour')))->toBe('3 days ago');
});

it('returns no tooltip for a blank state', function (): void {
    expect(formattingColumn()->dateTooltip()->getTooltip())->toBeNull()
        ->and(formattingColumn()->sinceTooltip()->getTooltip(''))->toBeNull();
});

it('formats money in the table currency and locale', function (): void {
    expect(numberColumn()->money()->formatState(1234.5))->toBe('$1,234.50')
        ->and(numberColumn()->money('eur', locale: 'de_DE')->formatState(1234.5))->toBe("1.234,50\u{a0}€")
        ->and(TextColumn::make('price')->table(Table::make(new TableHost())->defaultCurrency('eur')->defaultNumberLocale('de_DE'))->money()->formatState(1234.5))->toBe("1.234,50\u{a0}€");
});

it('divides money only when divideBy is truthy, so the zero default does not', function (): void {
    expect(numberColumn()->money()->formatState(1000))->toBe('$1,000.00')
        ->and(numberColumn()->money(divideBy: 100)->formatState(1000))->toBe('$10.00')
        ->and(numberColumn()->money(divideBy: fn (): int => 100)->formatState(1000))->toBe('$10.00');
});

it('honours an explicit money precision and unwraps backed enum arguments', function (): void {
    expect(numberColumn()->money(decimalPlaces: 0)->formatState(1234.5))->toBe('$1,234')
        ->and(numberColumn()->money(EnumArgument::Currency, locale: EnumArgument::Locale)->formatState(1234.5))->toBe("1.234,50\u{a0}€");
});

it('passes a non-numeric money state through and nulls a blank one', function (): void {
    expect(numberColumn()->money()->formatState('n/a'))->toBe('n/a')
        ->and(numberColumn()->money()->formatState(null))->toBeNull();
});

it('formats numerics through the intl decimal formatter', function (): void {
    expect(numberColumn('plays')->numeric()->formatState(1234.5678))->toBe('1,234.568')
        ->and(numberColumn('plays')->numeric(decimalPlaces: 2)->formatState(1234.5678))->toBe('1,234.57')
        ->and(numberColumn('plays')->numeric(decimalPlaces: 4, maxDecimalPlaces: 2)->formatState(1234.5678))->toBe('1,234.57')
        ->and(numberColumn('plays')->numeric(locale: 'de_DE')->formatState(1000))->toBe('1.000')
        ->and(numberColumn('plays')->numeric(locale: EnumArgument::Locale)->formatState(1000))->toBe('1.000');
});

/*
 * `ArgumentValue::Default` is what tells the two arms apart: an explicitly passed separator —
 * `null` included — routes to `number_format()`, while the untouched sentinel keeps the
 * locale-aware formatter. Passing one and leaving the other is the case a boolean flag would
 * lose, so both halves are asserted against the same number.
 */
it('routes to number_format only when a separator is explicitly passed', function (): void {
    expect(numberColumn('plays')->numeric(decimalPlaces: 2, decimalSeparator: ',', thousandsSeparator: ' ')->formatState(1234.5678))->toBe('1 234,57')
        ->and(numberColumn('plays')->numeric(decimalPlaces: 2, thousandsSeparator: ' ')->formatState(1234.5678))->toBe('1 234.57')
        ->and(numberColumn('plays')->numeric(decimalPlaces: 2, decimalSeparator: null, thousandsSeparator: null)->formatState(1234.5678))->toBe('1,234.57')
        ->and(numberColumn('plays')->numeric(thousandsSeparator: '')->formatState(1234.5678))->toBe('1235');
});

it('passes a non-numeric numeric state through and nulls a blank one', function (): void {
    expect(numberColumn('plays')->numeric()->formatState('n/a'))->toBe('n/a')
        ->and(numberColumn('plays')->numeric()->formatState(null))->toBeNull();
});

it('falls back to the process locale when neither the call nor the table names one', function (): void {
    $previous = Locale::getDefault();
    Locale::setDefault('de_DE');

    try {
        expect(TextColumn::make('plays')->table(Table::make(new TableHost()))->numeric()->formatState(1000))->toBe('1.000');
    } finally {
        Locale::setDefault($previous);
    }
});

it('sanitizes an html state and keeps it out of the truncation', function (): void {
    $column = sanitizingColumn()->html()->limit(4);

    expect((string) $column->formatState('<p>Hello <script>alert(1)</script>world</p>'))->toBe('<p>Hello world</p>')
        ->and($column->formatState('<p>Hello</p>'))->toBeInstanceOf(Markup::class);
});

it('is html whenever it is markdown', function (): void {
    expect(TextColumn::make('body')->isHtml())->toBeFalse()
        ->and(TextColumn::make('body')->html()->isHtml())->toBeTrue()
        ->and(TextColumn::make('body')->html(fn (): bool => false)->isHtml())->toBeFalse()
        ->and(TextColumn::make('body')->markdown()->isHtml())->toBeTrue()
        ->and(TextColumn::make('body')->markdown()->isMarkdown())->toBeTrue()
        ->and(TextColumn::make('body')->isMarkdown())->toBeFalse();
});

it('converts markdown before sanitizing it', function (): void {
    expect((string) sanitizingColumn()->markdown()->formatState('**bold** <script>alert(1)</script>'))
        ->toBe("<p><strong>bold</strong> &lt;script&gt;alert(1)&lt;/script&gt;</p>\n");
});

it('carries the commonmark options and extensions it is given', function (): void {
    $extension = new TableExtension();

    expect(TextColumn::make('body')->getCommonMarkOptions())->toBe([])
        ->and(TextColumn::make('body')->getCommonMarkExtensions())->toBe([])
        ->and(TextColumn::make('body')->commonMarkOptions(['html_input' => 'strip'])->getCommonMarkOptions())->toBe(['html_input' => 'strip'])
        ->and(TextColumn::make('body')->commonMarkOptions(fn (): array => ['html_input' => 'escape'])->getCommonMarkOptions())->toBe(['html_input' => 'escape'])
        ->and(TextColumn::make('body')->commonMarkExtensions([$extension])->getCommonMarkExtensions())->toBe([$extension]);
});

/*
 * The extensions are added to the converter's *environment* rather than passed to its
 * constructor, which is the one part of the conversion a getter assertion cannot reach.
 * The negative half picks a syntax GFM does not carry — a description list; tables and
 * task lists would both pass without the extension, since GFM bundles those.
 */
it('adds each commonmark extension to the conversion', function (): void {
    $markdown = "Term\n: Definition";

    expect((string) sanitizingColumn()->markdown()->commonMarkExtensions([new DescriptionListExtension()])->formatState($markdown))
        ->toContain('<dl>')
        ->and((string) sanitizingColumn()->markdown()->formatState($markdown))
        ->not->toContain('<dl>');
});

it('applies the commonmark options to the conversion', function (): void {
    expect((string) sanitizingColumn()->markdown()->commonMarkOptions(['html_input' => 'escape'])->formatState('<em>raw</em>'))
        ->toBe("<p>&lt;em&gt;raw&lt;/em&gt;</p>\n");
});

/*
 * The `class_exists()` guard is what stands between a `markdown()` column and a fatal on an
 * install without the suggested package. It cannot be proven by uninstalling the library here,
 * so the guard site itself is read: deleting it, or replacing it with a silent fall-through,
 * fails this.
 */
it('guards the markdown conversion on the suggested package being installed', function (): void {
    $source = file_get_contents(dirname(__DIR__, 4) . '/src/Columns/Concerns/CanFormatState.php');

    expect($source)->toContain('class_exists(GithubFlavoredMarkdownConverter::class)')
        ->and($source)->toContain('league/commonmark');
});

it('refuses html without a sanitizer rather than emitting unsanitized markup', function (): void {
    expect(fn (): mixed => TextColumn::make('body')->html()->formatState('<p>hi</p>'))
        ->toThrow(LogicException::class, 'Column [body] has no HTML sanitizer');
});
