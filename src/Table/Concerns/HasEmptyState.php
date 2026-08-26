<?php

declare(strict_types=1);

namespace Sylament\Tables\Table\Concerns;

use BackedEnum;
use Closure;
use Twig\Markup;

trait HasEmptyState
{
    protected string|Markup|Closure|null $emptyStateHeading = null;

    protected string|Markup|Closure|null $emptyStateDescription = null;

    protected string|BackedEnum|Markup|Closure|null $emptyStateIcon = null;

    public function emptyStateHeading(string|Markup|Closure|null $heading): static
    {
        $this->emptyStateHeading = $heading;

        return $this;
    }

    public function emptyStateDescription(string|Markup|Closure|null $description): static
    {
        $this->emptyStateDescription = $description;

        return $this;
    }

    public function emptyStateIcon(string|BackedEnum|Markup|Closure|null $icon): static
    {
        $this->emptyStateIcon = $icon;

        return $this;
    }

    public function getEmptyStateHeading(): string|Markup
    {
        return $this->evaluate($this->emptyStateHeading) ?? sprintf('No %s', $this->getPluralRecordLabel());
    }

    public function getEmptyStateDescription(): string|Markup|null
    {
        return $this->evaluate($this->emptyStateDescription);
    }

    public function getEmptyStateIcon(): string|BackedEnum|Markup|null
    {
        return $this->evaluate($this->emptyStateIcon);
    }
}
