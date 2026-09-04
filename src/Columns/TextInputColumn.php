<?php

declare(strict_types=1);

namespace Sylament\Tables\Columns;

use BackedEnum;
use Closure;
use Sylament\Forms\Components\Concerns\HasExtraInputAttributes;
use Sylament\Forms\Components\Concerns\HasStep;
use Sylament\Tables\Columns\Concerns\CanBeValidated;
use Sylament\Tables\Columns\Concerns\CanUpdateState;
use Sylament\Tables\Columns\Contracts\Editable;
use Twig\Markup;

class TextInputColumn extends Column implements Editable
{
    use CanBeValidated;
    use CanUpdateState;
    use HasExtraInputAttributes;
    use HasStep;

    protected string|Closure|null $template = '@SylamentTables/partials/TextInputColumn/index.html.twig';

    protected string|Closure|null $type = null;

    protected string|Closure|null $inputMode = null;

    protected string|Markup|Closure|null $prefixLabel = null;

    protected string|Markup|Closure|null $suffixLabel = null;

    protected string|BackedEnum|Markup|Closure|null $prefixIcon = null;

    protected string|BackedEnum|Markup|Closure|null $suffixIcon = null;

    /**
     * @var string|array<string>|Closure|null
     */
    protected string|array|Closure|null $prefixIconColor = null;

    /**
     * @var string|array<string>|Closure|null
     */
    protected string|array|Closure|null $suffixIconColor = null;

    protected bool|Closure $isPrefixInline = false;

    protected bool|Closure $isSuffixInline = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->disabledClick();
    }

    public function type(string|Closure|null $type): static
    {
        $this->type = $type;

        return $this;
    }

    public function getType(): string
    {
        return $this->evaluate($this->type) ?? 'text';
    }

    public function inputMode(string|Closure|null $mode): static
    {
        $this->inputMode = $mode;

        return $this;
    }

    public function getInputMode(): ?string
    {
        return $this->evaluate($this->inputMode);
    }

    public function prefix(string|Markup|Closure|null $label, bool|Closure $isInline = false): static
    {
        $this->prefixLabel = $label;
        $this->inlinePrefix($isInline);

        return $this;
    }

    public function suffix(string|Markup|Closure|null $label, bool|Closure $isInline = false): static
    {
        $this->suffixLabel = $label;
        $this->inlineSuffix($isInline);

        return $this;
    }

    public function inlinePrefix(bool|Closure $isInline = true): static
    {
        $this->isPrefixInline = $isInline;

        return $this;
    }

    public function inlineSuffix(bool|Closure $isInline = true): static
    {
        $this->isSuffixInline = $isInline;

        return $this;
    }

    public function prefixIcon(string|BackedEnum|Markup|Closure|null $icon, bool|Closure $isInline = false): static
    {
        $this->prefixIcon = $icon;
        $this->inlinePrefix($isInline);

        return $this;
    }

    public function suffixIcon(string|BackedEnum|Markup|Closure|null $icon, bool|Closure $isInline = false): static
    {
        $this->suffixIcon = $icon;
        $this->inlineSuffix($isInline);

        return $this;
    }

    /**
     * @param string|array<string>|Closure|null $color
     */
    public function prefixIconColor(string|array|Closure|null $color = null): static
    {
        $this->prefixIconColor = $color;

        return $this;
    }

    /**
     * @param string|array<string>|Closure|null $color
     */
    public function suffixIconColor(string|array|Closure|null $color = null): static
    {
        $this->suffixIconColor = $color;

        return $this;
    }

    public function getPrefixLabel(): string|Markup|null
    {
        return $this->evaluate($this->prefixLabel);
    }

    public function getSuffixLabel(): string|Markup|null
    {
        return $this->evaluate($this->suffixLabel);
    }

    public function getPrefixIcon(): string|BackedEnum|Markup|null
    {
        return $this->evaluate($this->prefixIcon);
    }

    public function getSuffixIcon(): string|BackedEnum|Markup|null
    {
        return $this->evaluate($this->suffixIcon);
    }

    /**
     * @return string|array<string>|null
     */
    public function getPrefixIconColor(): string|array|null
    {
        return $this->evaluate($this->prefixIconColor);
    }

    /**
     * @return string|array<string>|null
     */
    public function getSuffixIconColor(): string|array|null
    {
        return $this->evaluate($this->suffixIconColor);
    }

    public function isPrefixInline(): bool
    {
        return (bool) $this->evaluate($this->isPrefixInline);
    }

    public function isSuffixInline(): bool
    {
        return (bool) $this->evaluate($this->isSuffixInline);
    }
}
