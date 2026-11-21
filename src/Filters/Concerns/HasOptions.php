<?php

declare(strict_types=1);

namespace Sylament\Tables\Filters\Concerns;

use Closure;
use Symfony\Contracts\Translation\TranslatableInterface;

trait HasOptions
{
    /**
     * @var array<string|int, string|TranslatableInterface|array<string|int, string|TranslatableInterface>>|Closure|null
     */
    protected array|Closure|null $options = null;

    /**
     * @param array<string|int, string|TranslatableInterface|array<string|int, string|TranslatableInterface>>|Closure|null $options
     */
    public function options(array|Closure|null $options): static
    {
        $this->options = $options;

        return $this;
    }

    /**
     * Each label as the text it renders, one group level deep: a message is translated here, so the
     * indicator that reads a label back by its posted value gets text too. A group's label is the
     * array key, which cannot be a message.
     *
     * @return array<string|int, string|array<string|int, string>>
     */
    public function getOptions(): array
    {
        $options = [];

        foreach ($this->evaluate($this->options) ?? [] as $value => $label) {
            $options[$value] = is_array($label)
                ? array_map(fn (string|TranslatableInterface $groupLabel): string => (string) $this->translateText($groupLabel), $label)
                : (string) $this->translateText($label);
        }

        return $options;
    }
}
