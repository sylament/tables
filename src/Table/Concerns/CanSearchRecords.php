<?php

declare(strict_types=1);

namespace Sylament\Tables\Table\Concerns;

use Closure;

trait CanSearchRecords
{
    protected ?bool $isSearchable = null;

    /**
     * @var array<string|Closure>
     */
    protected array $extraSearchableColumns = [];

    protected string|Closure|null $searchPlaceholder = null;

    protected ?string $searchDebounce = null;

    protected bool|Closure $isSearchOnBlur = false;

    /**
     * @param bool|array<string|Closure> $condition
     */
    public function searchable(bool|array $condition = true): static
    {
        if ($condition === true) {
            $this->isSearchable = true;
        } elseif (!$condition) {
            $this->isSearchable = false;
            $this->extraSearchableColumns = [];
        } else {
            $this->isSearchable = true;
            $this->extraSearchableColumns = $condition;
        }

        return $this;
    }

    public function isSearchable(): bool
    {
        if (is_bool($this->isSearchable)) {
            return $this->isSearchable;
        }

        if ($this->getExtraSearchableColumns() !== []) {
            return true;
        }

        foreach ($this->getColumns() as $column) {
            if ($column->isGloballySearchable()) {
                return true;
            }
        }

        return false;
    }

    public function searchPlaceholder(string|Closure|null $searchPlaceholder): static
    {
        $this->searchPlaceholder = $searchPlaceholder;

        return $this;
    }

    public function getSearchPlaceholder(): ?string
    {
        return $this->evaluate($this->searchPlaceholder);
    }

    public function searchDebounce(?string $debounce): static
    {
        $this->searchDebounce = $debounce;

        return $this;
    }

    public function getSearchDebounce(): string
    {
        return $this->searchDebounce ?? '500ms';
    }

    /**
     * The debounce as ux-live-component's `debounce(N)` modifier wants it —
     * Filament's duration strings (`500ms`, `1s`, bare numbers) translated,
     * anything unparseable falling back to 500.
     */
    public function getSearchDebounceMilliseconds(): int
    {
        if (preg_match('/^(\d+(?:\.\d+)?)\s*(ms|s)?$/', mb_trim($this->getSearchDebounce()), $matches) !== 1) {
            return 500;
        }

        $value = (float) $matches[1];

        return (int) round(($matches[2] ?? 'ms') === 's' ? $value * 1000 : $value);
    }

    public function searchOnBlur(bool|Closure $condition = true): static
    {
        $this->isSearchOnBlur = $condition;

        return $this;
    }

    public function isSearchOnBlur(): bool
    {
        return (bool) $this->evaluate($this->isSearchOnBlur);
    }

    /**
     * @return array<string|Closure>
     */
    public function getExtraSearchableColumns(): array
    {
        return $this->extraSearchableColumns;
    }
}
