<?php

declare(strict_types=1);

namespace Sylament\Tables\Columns\Concerns;

use BackedEnum;
use Closure;
use LogicException;
use Stringable;
use Sylament\Support\Contracts\HasLabel;
use Twig\Markup;

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

        if (! $isMarkup) {
            $state = $this->truncateState($state);
        }

        return $this->affixState($state, $isMarkup);
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
