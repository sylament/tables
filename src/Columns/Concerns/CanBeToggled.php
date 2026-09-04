<?php

declare(strict_types=1);

namespace Sylament\Tables\Columns\Concerns;

use Closure;

trait CanBeToggled
{
    protected bool|Closure $isToggleable = false;

    protected bool|Closure $isToggledHiddenByDefault = false;

    public function toggleable(bool|Closure $condition = true, bool|Closure $isToggledHiddenByDefault = false): static
    {
        $this->isToggleable = $condition;
        $this->toggledHiddenByDefault($isToggledHiddenByDefault);

        return $this;
    }

    public function toggledHiddenByDefault(bool|Closure $condition = true): static
    {
        $this->isToggledHiddenByDefault = $condition;

        return $this;
    }

    public function isToggledHiddenByDefault(): bool
    {
        return (bool) $this->evaluate($this->isToggledHiddenByDefault);
    }

    /**
     * A column the developer hid is not one the user may restore.
     */
    public function isToggleable(): bool
    {
        if ($this->isHidden()) {
            return false;
        }

        return (bool) $this->evaluate($this->isToggleable);
    }

    /**
     * The user's choice, asked of the host — never asked for a column the
     * manager does not track.
     */
    public function isToggledHidden(): bool
    {
        if (!$this->isToggleable()) {
            return false;
        }

        return $this->getLiveComponent()->isTableColumnToggledHidden($this->getName());
    }
}
