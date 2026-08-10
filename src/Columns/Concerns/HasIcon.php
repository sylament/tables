<?php

declare(strict_types=1);

namespace Sylament\Tables\Columns\Concerns;

use BackedEnum;
use Closure;
use LogicException;
use Sylament\Support\Contracts\HasIcon as IconContract;
use Sylament\Support\Enums\IconPosition;
use Sylament\Tables\Columns\Column;
use Twig\Markup;

use function Sylament\Support\blank;
use function Sylament\Support\filled;

trait HasIcon
{
    protected string|BackedEnum|Markup|bool|Closure|null $icon = null;

    protected IconPosition|string|Closure|null $iconPosition = null;

    public function icon(string|BackedEnum|Markup|bool|Closure|null $icon): static
    {
        $this->icon = $icon;

        return $this;
    }

    /**
     * @param array<mixed>|Closure $icons
     */
    public function icons(array|Closure $icons): static
    {
        $this->icon(function (Column $column, mixed $state) use ($icons) {
            $icons = $column->evaluate($icons);

            $icon = null;

            foreach ($icons as $conditionalIcon => $condition) {
                if (is_numeric($conditionalIcon)) {
                    $icon = $condition;
                } elseif ($condition instanceof Closure && $this->evaluateIconCondition($condition, $state)) {
                    $icon = $conditionalIcon;
                } elseif ($condition === $state) {
                    $icon = $conditionalIcon;
                }
            }

            return $icon;
        });

        return $this;
    }

    public function iconPosition(IconPosition|string|Closure|null $iconPosition): static
    {
        $this->iconPosition = $iconPosition;

        return $this;
    }

    public function getIcon(mixed $state): string|BackedEnum|Markup|null
    {
        $icon = $this->evaluate($this->icon, [
            'state' => $state,
        ]);

        if ($icon === true) {
            throw new LogicException(sprintf('Icon of column [%s] must be an icon name, an enum, markup, or false; true given.', $this->getName()));
        }

        if ($icon === false) {
            return null;
        }

        if (filled($icon)) {
            return $icon;
        }

        if (! $state instanceof IconContract) {
            return null;
        }

        return $state->getIcon();
    }

    public function getIconPosition(): IconPosition
    {
        $position = $this->evaluate($this->iconPosition);

        if ($position instanceof IconPosition) {
            return $position;
        }

        if (blank($position)) {
            return IconPosition::Before;
        }

        return IconPosition::tryFrom($position) ?? IconPosition::Before;
    }

    public function hasIcon(): bool
    {
        return $this->icon !== null && $this->icon !== false;
    }

    protected function evaluateIconCondition(Closure $condition, mixed $state): mixed
    {
        return $this->evaluate($condition, [
            'state' => $state,
        ]);
    }
}
