<?php

declare(strict_types=1);

namespace Sylament\Tables\Columns\Summarizers\Concerns;

use BackedEnum;
use Closure;
use League\CommonMark\Extension\ExtensionInterface;
use League\CommonMark\GithubFlavoredMarkdownConverter;
use Locale;
use LogicException;
use NumberFormatter;
use Stringable;
use Sylament\Support\Contracts\HasLabel;
use Sylament\Support\Enums\ArgumentValue;
use Sylament\Tables\Columns\Summarizers\Summarizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerInterface;
use Symfony\Contracts\Service\Attribute\Required;
use Twig\Markup;

use function Sylament\Support\blank;
use function Sylament\Support\filled;
use function Symfony\Component\String\u;

trait CanFormatState
{
    protected mixed $formatStateUsing = null;

    protected int|Closure|null $characterLimit = null;

    protected string|Closure|null $characterLimitEnd = null;

    protected string|Closure|null $placeholder = null;

    protected string|Markup|Closure|null $prefix = null;

    protected string|Markup|Closure|null $suffix = null;

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

    public function money(string|BackedEnum|Closure|null $currency = null, int|Closure $divideBy = 0, string|BackedEnum|Closure|null $locale = null, int|Closure|null $decimalPlaces = null): static
    {
        $this->formatStateUsing(static function (Summarizer $summarizer, mixed $state) use ($currency, $divideBy, $locale, $decimalPlaces): mixed {
            if (blank($state)) {
                return null;
            }

            if (!is_numeric($state)) {
                return $state;
            }

            $amount = (float) $state;
            $divisor = $summarizer->evaluate($divideBy);

            if ($divisor) {
                $amount /= $divisor;
            }

            $formatter = new NumberFormatter($summarizer->resolveNumberLocale($locale), NumberFormatter::CURRENCY);
            $precision = $summarizer->evaluate($decimalPlaces);

            if ($precision !== null) {
                $formatter->setAttribute(NumberFormatter::FRACTION_DIGITS, $precision);
            }

            $code = $summarizer->evaluate($currency) ?? $summarizer->getTable()->getDefaultCurrency();

            return $summarizer->formatterResult($formatter->formatCurrency($amount, $code instanceof BackedEnum ? (string) $code->value : $code));
        });

        return $this;
    }

    public function numeric(int|Closure|null $decimalPlaces = null, string|Closure|null|ArgumentValue $decimalSeparator = ArgumentValue::Default, string|Closure|null|ArgumentValue $thousandsSeparator = ArgumentValue::Default, int|Closure|null $maxDecimalPlaces = null, string|BackedEnum|Closure|null $locale = null): static
    {
        $this->formatStateUsing(static function (Summarizer $summarizer, mixed $state) use ($decimalPlaces, $decimalSeparator, $locale, $maxDecimalPlaces, $thousandsSeparator): mixed {
            if (blank($state)) {
                return null;
            }

            if (!is_numeric($state)) {
                return $state;
            }

            $precision = $summarizer->evaluate($decimalPlaces);
            $decimalSeparator = $summarizer->evaluate($decimalSeparator);
            $thousandsSeparator = $summarizer->evaluate($thousandsSeparator);

            if ($decimalSeparator !== ArgumentValue::Default || $thousandsSeparator !== ArgumentValue::Default) {
                return number_format(
                    (float) $state,
                    $precision ?? 0,
                    $decimalSeparator === ArgumentValue::Default ? '.' : $decimalSeparator,
                    $thousandsSeparator === ArgumentValue::Default ? ',' : $thousandsSeparator,
                );
            }

            $formatter = new NumberFormatter($summarizer->resolveNumberLocale($locale), NumberFormatter::DECIMAL);
            $maxPrecision = $summarizer->evaluate($maxDecimalPlaces);

            if ($maxPrecision !== null) {
                $formatter->setAttribute(NumberFormatter::MAX_FRACTION_DIGITS, $maxPrecision);
            } elseif ($precision !== null) {
                $formatter->setAttribute(NumberFormatter::FRACTION_DIGITS, $precision);
            }

            return $summarizer->formatterResult($formatter->format((float) $state));
        });

        return $this;
    }

    public function placeholder(string|Closure|null $placeholder): static
    {
        $this->placeholder = $placeholder;

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

    public function getPrefix(): string|Markup|null
    {
        return $this->evaluate($this->prefix);
    }

    public function getSuffix(): string|Markup|null
    {
        return $this->evaluate($this->suffix);
    }

    public function isHtml(): bool
    {
        return (bool) $this->evaluate($this->isHtml) || $this->isMarkdown();
    }

    public function isMarkdown(): bool
    {
        return (bool) $this->evaluate($this->isMarkdown);
    }

    /**
     * A range's pair is handed back whole: the two ends are produced together by the
     * presets that format them, and the template renders each of them itself. A list
     * summary never arrives here as a list at all — its own template data is the raw
     * values and it formats each entry through this method one at a time, so a
     * closure set on one sees a value rather than the collection.
     */
    public function formatState(mixed $state): mixed
    {
        if ($this->formatStateUsing !== null) {
            $state = $this->evaluate($this->formatStateUsing, [
                'state' => $state,
            ]);
        }

        if (is_array($state)) {
            return $state;
        }

        $isMarkup = $state instanceof Markup;

        if ($state instanceof HasLabel) {
            $state = $state->getLabel();

            $isMarkup = $state instanceof Markup;
        }

        if ($state instanceof BackedEnum) {
            $state = $state->value;
        }

        if ($state !== null && !is_scalar($state) && !$state instanceof Stringable) {
            throw new LogicException(sprintf('State of summarizer [%s] must be stringable; %s given.', static::class, get_debug_type($state)));
        }

        $state = (string) $state;

        if (!$isMarkup && $this->isHtml()) {
            $state = $this->sanitizeState($state);
            $isMarkup = true;
        }

        if (!$isMarkup) {
            $state = $this->truncateState($state);
        }

        $affixed = $this->affixState($state, $isMarkup);

        return mb_trim((string) $affixed) === '' ? $this->evaluate($this->placeholder) : $affixed;
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
            throw new LogicException(sprintf('Summarizer [%s] could not format its state as a number.', static::class));
        }

        return $formatted;
    }

    /**
     * Markdown first, the sanitizer always last: the converter passes raw HTML through
     * by design, so the sanitizer is the only guard in front of the emitted `Markup`.
     */
    private function sanitizeState(string $state): string
    {
        if ($this->isMarkdown()) {
            if (!class_exists(GithubFlavoredMarkdownConverter::class)) {
                throw new LogicException(sprintf('Summarizer [%s] sets markdown(), which needs the suggested league/commonmark package: run `composer require league/commonmark`.', static::class));
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
        return $this->htmlSanitizer ?? throw new LogicException(sprintf('Summarizer [%s] has no HTML sanitizer, which html() and markdown() need: build the summarizer through the container rather than with new.', static::class));
    }

    private function truncateState(string $state): string
    {
        if ($characterLimit = $this->getCharacterLimit()) {
            $string = u($state);

            if ($string->length() > $characterLimit) {
                $state = (string) $string->slice(0, $characterLimit)->trimEnd()->append($this->getCharacterLimitEnd() ?? '');
            }
        }

        return $state;
    }

    private function affixState(string $state, bool $isMarkup): string|Markup
    {
        $prefix = $this->getPrefix();
        $suffix = $this->getSuffix();

        if (!$isMarkup && (($prefix instanceof Markup) || ($suffix instanceof Markup))) {
            $isMarkup = true;
            $state = $this->escape($state);
        }

        if (filled($prefix)) {
            $state = ($isMarkup && !$prefix instanceof Markup ? $this->escape((string) $prefix) : (string) $prefix) . $state;
        }

        if (filled($suffix)) {
            $state .= $isMarkup && !$suffix instanceof Markup ? $this->escape((string) $suffix) : (string) $suffix;
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
