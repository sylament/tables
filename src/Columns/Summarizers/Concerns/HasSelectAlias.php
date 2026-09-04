<?php

declare(strict_types=1);

namespace Sylament\Tables\Columns\Summarizers\Concerns;

/**
 * The alias trio Filament copies into each of its three batchable summarizers,
 * shared here — with the alias itself minted by the host rather than randomly,
 * because a DQL result variable cannot start with a digit and a reproducible
 * select list is what a test can read.
 */
trait HasSelectAlias
{
    protected ?string $selectAlias = null;

    public function selectAlias(?string $alias): static
    {
        $this->selectAlias = $alias;

        return $this;
    }

    /**
     * The alias the batch pushed, or a stable per-instance one for a caller that
     * reads the select statements outside a batch.
     */
    public function getSelectAlias(): string
    {
        return $this->selectAlias ??= 'sy_summary_' . spl_object_id($this);
    }

    public function getSelectedState(): int|float|null
    {
        return $this->hasSelectedState() ? self::toNumber($this->selectedState[$this->getSelectAlias()]) : null;
    }

    /**
     * Read off the raw alias rather than the getter: a summarizer the batch never
     * pushed an alias onto has no figure in the row, and minting one here would
     * look one up that could never be there.
     */
    public function hasSelectedState(): bool
    {
        return array_key_exists($this->selectAlias ?? '', $this->selectedState);
    }
}
