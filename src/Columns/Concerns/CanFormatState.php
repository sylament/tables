<?php

declare(strict_types=1);

namespace Sylament\Tables\Columns\Concerns;

use BackedEnum;
use Closure;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use League\CommonMark\Extension\ExtensionInterface;
use League\CommonMark\GithubFlavoredMarkdownConverter;
use Locale;
use LogicException;
use NumberFormatter;
use Stringable;
use Sylament\Support\Contracts\HasLabel;
use Sylament\Support\Enums\ArgumentValue;
use Sylament\Tables\Columns\TextColumn;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerInterface;
use Symfony\Contracts\Service\Attribute\Required;
use Twig\Markup;

use function Sylament\Support\blank;
use function Sylament\Support\diff_for_humans;
use function Sylament\Support\filled;
use function Symfony\Component\String\u;

trait CanFormatState
{
    protected mixed $formatStateUsing = null;

    protected int|Closure|null $characterLimit = null;

    protected string|Closure|null $characterLimitEnd = null;

    protected int|Closure|null $wordLimit = null;

    protected string|Closure|null $wordLimitEnd = null;

    protected string|Markup|Closure|null $prefix = null;

    protected string|Markup|Closure|null $suffix = null;

    protected string|Closure|null $timezone = null;

    protected bool|Closure $isHtml = false;

    protected bool|Closure $isMarkdown = false;

    /**
     * @var array<string, mixed>|Closure|null
     */
    protected array|Closure|null $commonMarkOptions = null;

    /**
     * @var array<array-key, ExtensionInterface>|Closure|null
     */
    protected array|Closure|null $commonMarkExtensions = null;

    protected ?HtmlSanitizerInterface $htmlSanitizer = null;

    protected bool $isDate = false;

    protected bool $isDateTime = false;

    protected bool $isMoney = false;

    protected bool $isNumeric = false;

    protected bool $isTime = false;

    #[Required]
    public function setHtmlSanitizer(HtmlSanitizerInterface $htmlSanitizer): void
    {
        $this->htmlSanitizer = $htmlSanitizer;
    }

    public function formatStateUsing(mixed $callback): static
    {
        $this->formatStateUsing = $callback;

        return $this;
    }

    public function limit(int|Closure|null $length = 100, string|Closure|null $end = '...'): static
    {
        $this->characterLimit = $length;
        $this->characterLimitEnd = $end;

        return $this;
    }

    public function words(int|Closure|null $words = 100, string|Closure|null $end = '...'): static
    {
        $this->wordLimit = $words;
        $this->wordLimitEnd = $end;

        return $this;
    }

    public function date(string|Closure|null $format = null, string|Closure|null $timezone = null): static
    {
        $this->isDate = true;

        $this->formatStateUsing(static function (TextColumn $column, mixed $state) use ($format, $timezone): ?string {
            if (blank($state)) {
                return null;
            }

            return $column->toDateTimeImmutable($state)
                ->setTimezone($column->resolveTimeZone($timezone))
                ->format($column->evaluate($format) ?? $column->getTable()->getDefaultDateDisplayFormat());
        });

        return $this;
    }

    public function dateTime(string|Closure|null $format = null, string|Closure|null $timezone = null): static
    {
        $this->isDateTime = true;

        $format ??= static fn (TextColumn $column): string => $column->getTable()->getDefaultDateTimeDisplayFormat();

        $this->date($format, $timezone);

        return $this;
    }

    public function time(string|Closure|null $format = null, string|Closure|null $timezone = null): static
    {
        $this->isTime = true;

        $format ??= static fn (TextColumn $column): string => $column->getTable()->getDefaultTimeDisplayFormat();

        $this->date($format, $timezone);

        return $this;
    }

    public function since(string|Closure|null $timezone = null): static
    {
        $this->isDateTime = true;

        $this->formatStateUsing(static function (TextColumn $column, mixed $state) use ($timezone): ?string {
            if (blank($state)) {
                return null;
            }

            $timeZone = $column->resolveTimeZone($timezone);

            return diff_for_humans(
                $column->toDateTimeImmutable($state)->setTimezone($timeZone),
                new DateTimeImmutable('now', $timeZone),
            );
        });

        return $this;
    }

    public function dateTooltip(string|Closure|null $format = null, string|Closure|null $timezone = null): static
    {
        $this->tooltip(static function (TextColumn $column, mixed $state) use ($format, $timezone): ?string {
            if (blank($state)) {
                return null;
            }

            return $column->toDateTimeImmutable($state)
                ->setTimezone($column->resolveTimeZone($timezone))
                ->format($column->evaluate($format) ?? $column->getTable()->getDefaultDateDisplayFormat());
        });

        return $this;
    }

    public function dateTimeTooltip(string|Closure|null $format = null, string|Closure|null $timezone = null): static
    {
        $format ??= static fn (TextColumn $column): string => $column->getTable()->getDefaultDateTimeDisplayFormat();

        $this->dateTooltip($format, $timezone);

        return $this;
    }

    public function timeTooltip(string|Closure|null $format = null, string|Closure|null $timezone = null): static
    {
        $format ??= static fn (TextColumn $column): string => $column->getTable()->getDefaultTimeDisplayFormat();

        $this->dateTooltip($format, $timezone);

        return $this;
    }

    public function sinceTooltip(string|Closure|null $timezone = null): static
    {
        $this->tooltip(static function (TextColumn $column, mixed $state) use ($timezone): ?string {
            if (blank($state)) {
                return null;
            }

            $timeZone = $column->resolveTimeZone($timezone);

            return diff_for_humans(
                $column->toDateTimeImmutable($state)->setTimezone($timeZone),
                new DateTimeImmutable('now', $timeZone),
            );
        });

        return $this;
    }

    public function timezone(string|Closure|null $timezone): static
    {
        $this->timezone = $timezone;

        return $this;
    }

    public function money(string|BackedEnum|Closure|null $currency = null, int|Closure $divideBy = 0, string|BackedEnum|Closure|null $locale = null, int|Closure|null $decimalPlaces = null): static
    {
        $this->isMoney = true;

        $this->formatStateUsing(static function (TextColumn $column, mixed $state) use ($currency, $divideBy, $locale, $decimalPlaces): mixed {
            if (blank($state)) {
                return null;
            }

            if (!is_numeric($state)) {
                return $state;
            }

            $amount = (float) $state;
            $divisor = $column->evaluate($divideBy);

            if ($divisor) {
                $amount /= $divisor;
            }

            $formatter = new NumberFormatter($column->resolveNumberLocale($locale), NumberFormatter::CURRENCY);
            $precision = $column->evaluate($decimalPlaces);

            if ($precision !== null) {
                $formatter->setAttribute(NumberFormatter::FRACTION_DIGITS, $precision);
            }

            $code = $column->evaluate($currency) ?? $column->getTable()->getDefaultCurrency();

            return $column->formatterResult($formatter->formatCurrency($amount, $code instanceof BackedEnum ? (string) $code->value : $code));
        });

        return $this;
    }

    public function numeric(int|Closure|null $decimalPlaces = null, string|Closure|null|ArgumentValue $decimalSeparator = ArgumentValue::Default, string|Closure|null|ArgumentValue $thousandsSeparator = ArgumentValue::Default, int|Closure|null $maxDecimalPlaces = null, string|BackedEnum|Closure|null $locale = null): static
    {
        $this->isNumeric = true;

        $this->formatStateUsing(static function (TextColumn $column, mixed $state) use ($decimalPlaces, $decimalSeparator, $locale, $maxDecimalPlaces, $thousandsSeparator): mixed {
            if (blank($state)) {
                return null;
            }

            if (!is_numeric($state)) {
                return $state;
            }

            $precision = $column->evaluate($decimalPlaces);
            $decimalSeparator = $column->evaluate($decimalSeparator);
            $thousandsSeparator = $column->evaluate($thousandsSeparator);

            if ($decimalSeparator !== ArgumentValue::Default || $thousandsSeparator !== ArgumentValue::Default) {
                return number_format(
                    (float) $state,
                    $precision ?? 0,
                    $decimalSeparator === ArgumentValue::Default ? '.' : $decimalSeparator,
                    $thousandsSeparator === ArgumentValue::Default ? ',' : $thousandsSeparator,
                );
            }

            $formatter = new NumberFormatter($column->resolveNumberLocale($locale), NumberFormatter::DECIMAL);
            $maxPrecision = $column->evaluate($maxDecimalPlaces);

            if ($maxPrecision !== null) {
                $formatter->setAttribute(NumberFormatter::MAX_FRACTION_DIGITS, $maxPrecision);
            } elseif ($precision !== null) {
                $formatter->setAttribute(NumberFormatter::FRACTION_DIGITS, $precision);
            }

            return $column->formatterResult($formatter->format((float) $state));
        });

        return $this;
    }

    public function html(bool|Closure $condition = true): static
    {
        $this->isHtml = $condition;

        return $this;
    }

    public function markdown(bool|Closure $condition = true): static
    {
        $this->isMarkdown = $condition;

        return $this;
    }

    /**
     * @param array<string, mixed>|Closure|null $commonMarkOptions
     */
    public function commonMarkOptions(array|Closure|null $commonMarkOptions): static
    {
        $this->commonMarkOptions = $commonMarkOptions;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function getCommonMarkOptions(): array
    {
        return $this->evaluate($this->commonMarkOptions) ?? [];
    }

    /**
     * @param array<array-key, ExtensionInterface>|Closure|null $commonMarkExtensions
     */
    public function commonMarkExtensions(array|Closure|null $commonMarkExtensions): static
    {
        $this->commonMarkExtensions = $commonMarkExtensions;

        return $this;
    }

    /**
     * @return array<array-key, ExtensionInterface>
     */
    public function getCommonMarkExtensions(): array
    {
        return $this->evaluate($this->commonMarkExtensions) ?? [];
    }

    public function prefix(string|Markup|Closure|null $prefix): static
    {
        $this->prefix = $prefix;

        return $this;
    }

    public function suffix(string|Markup|Closure|null $suffix): static
    {
        $this->suffix = $suffix;

        return $this;
    }

    public function getCharacterLimit(): ?int
    {
        return $this->evaluate($this->characterLimit);
    }

    public function getCharacterLimitEnd(): ?string
    {
        return $this->evaluate($this->characterLimitEnd);
    }

    public function getWordLimit(): ?int
    {
        return $this->evaluate($this->wordLimit);
    }

    public function getWordLimitEnd(): ?string
    {
        return $this->evaluate($this->wordLimitEnd);
    }

    public function getPrefix(): string|Markup|null
    {
        return $this->evaluate($this->prefix);
    }

    public function getSuffix(): string|Markup|null
    {
        return $this->evaluate($this->suffix);
    }

    public function getTimezone(): string
    {
        return $this->evaluate($this->timezone) ?? date_default_timezone_get();
    }

    public function isHtml(): bool
    {
        return (bool) $this->evaluate($this->isHtml) || $this->isMarkdown();
    }

    public function isMarkdown(): bool
    {
        return (bool) $this->evaluate($this->isMarkdown);
    }

    public function isDate(): bool
    {
        return $this->isDate;
    }

    public function isDateTime(): bool
    {
        return $this->isDateTime;
    }

    public function isMoney(): bool
    {
        return $this->isMoney;
    }

    public function isNumeric(): bool
    {
        return $this->isNumeric;
    }

    public function isTime(): bool
    {
        return $this->isTime;
    }

    public function getFormattedState(): mixed
    {
        return $this->formatState($this->getState());
    }

    public function formatState(mixed $state): mixed
    {
        if ($this->formatStateUsing !== null) {
            $state = $this->evaluate($this->formatStateUsing, [
                'state' => $state,
            ]);
        }

        if (is_array($state)) {
            $state = json_encode($state);
        }

        $isMarkup = $state instanceof Markup;

        if ($state instanceof HasLabel) {
            $state = $state->getLabel();

            $isMarkup = $state instanceof Markup;
        }

        if ($state instanceof BackedEnum) {
            $state = $state->value;
        }

        if ($state === null) {
            return null;
        }

        if (! is_scalar($state) && ! $state instanceof Stringable) {
            throw new LogicException(sprintf('State of column [%s] must be stringable; %s given.', $this->getName(), get_debug_type($state)));
        }

        $state = (string) $state;

        if (! $isMarkup && $this->isHtml()) {
            $state = $this->sanitizeState($state);
            $isMarkup = true;
        }

        if (! $isMarkup) {
            $state = $this->truncateState($state);
        }

        return $this->affixState($state, $isMarkup);
    }

    /**
     * The timezone every date arm reads: the per-call one, else the column's, else the
     * process default — Filament's `FilamentTimezone` / `config('app.timezone')` fork
     * has no counterpart here, so one chain answers for `date()`, `time()` and `since()`.
     */
    private function resolveTimeZone(string|Closure|null $timezone): DateTimeZone
    {
        return new DateTimeZone($this->evaluate($timezone) ?? $this->getTimezone());
    }

    /**
     * The locale the intl formatters run under: the per-call one, else the table's,
     * else the process default, which Symfony's `Request::setLocale()` keeps in step
     * with the request locale.
     */
    private function resolveNumberLocale(string|BackedEnum|Closure|null $locale): string
    {
        $locale = $this->evaluate($locale) ?? $this->getTable()->getDefaultNumberLocale() ?? Locale::getDefault();

        return $locale instanceof BackedEnum ? (string) $locale->value : $locale;
    }

    private function formatterResult(string|false $formatted): string
    {
        if ($formatted === false) {
            throw new LogicException(sprintf('Column [%s] could not format its state as a number.', $this->getName()));
        }

        return $formatted;
    }

    private function toDateTimeImmutable(mixed $state): DateTimeImmutable
    {
        return match (true) {
            $state instanceof DateTimeInterface => DateTimeImmutable::createFromInterface($state),
            is_int($state) => new DateTimeImmutable('@' . $state),
            is_string($state) => new DateTimeImmutable($state),
            default => throw new LogicException(sprintf('State of column [%s] must be a date, a timestamp or a parseable string; %s given.', $this->getName(), get_debug_type($state))),
        };
    }

    /**
     * Markdown first, the sanitizer always last: the converter passes raw HTML through
     * by design, so the sanitizer is the only guard in front of the emitted `Markup`.
     */
    private function sanitizeState(string $state): string
    {
        if ($this->isMarkdown()) {
            if (! class_exists(GithubFlavoredMarkdownConverter::class)) {
                throw new LogicException(sprintf('Column [%s] sets markdown(), which needs the suggested league/commonmark package: run `composer require league/commonmark`.', $this->getName()));
            }

            $converter = new GithubFlavoredMarkdownConverter($this->getCommonMarkOptions());

            foreach ($this->getCommonMarkExtensions() as $extension) {
                $converter->getEnvironment()->addExtension($extension);
            }

            $state = (string) $converter->convert($state);
        }

        return $this->getHtmlSanitizer()->sanitize($state);
    }

    private function getHtmlSanitizer(): HtmlSanitizerInterface
    {
        return $this->htmlSanitizer ?? throw new LogicException(sprintf('Column [%s] has no HTML sanitizer, which html() and markdown() need: build the column through the container rather than with new.', $this->getName()));
    }

    private function truncateState(string $state): string
    {
        if ($characterLimit = $this->getCharacterLimit()) {
            $string = u($state);

            if ($string->length() > $characterLimit) {
                $state = (string) $string->slice(0, $characterLimit)->trimEnd()->append($this->getCharacterLimitEnd() ?? '');
            }
        }

        if (($wordLimit = $this->getWordLimit()) && preg_match('/^\s*+(?:\S++\s*+){1,' . $wordLimit . '}/u', $state, $matches) === 1) {
            if (mb_strlen($matches[0]) < mb_strlen($state)) {
                $state = mb_rtrim($matches[0]) . ($this->getWordLimitEnd() ?? '');
            }
        }

        return $state;
    }

    private function affixState(string $state, bool $isMarkup): string|Markup
    {
        $prefix = $this->getPrefix();
        $suffix = $this->getSuffix();

        if (! $isMarkup && (($prefix instanceof Markup) || ($suffix instanceof Markup))) {
            $isMarkup = true;
            $state = $this->escape($state);
        }

        if (filled($prefix)) {
            $state = ($isMarkup && ! $prefix instanceof Markup ? $this->escape((string) $prefix) : (string) $prefix) . $state;
        }

        if (filled($suffix)) {
            $state .= $isMarkup && ! $suffix instanceof Markup ? $this->escape((string) $suffix) : (string) $suffix;
        }

        return $isMarkup ? new Markup($state, 'UTF-8') : $state;
    }

    /**
     * Escapes with Twig's default `html` strategy. `formatState()` has no `Environment`
     * in scope, so it cannot reach Twig's escaper; the value is concatenated into a
     * `Markup` that templates render raw, and this is the only guard in front of it.
     */
    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
