<?php

declare(strict_types=1);

namespace Sylament\Tables\Columns\Concerns;

use Closure;
use LogicException;
use Sylament\Support\Contracts\HasColor as ColorContract;
use Sylament\Tables\Columns\Column;

use function Sylament\Support\filled;

trait HasColor
{
    /**
     * @var string|array<int|string, string|int>|bool|Closure|null
     */
    protected string|array|bool|Closure|null $color = null;

    /**
     * @param string|array<int|string, string|int>|bool|Closure|null $color
     */
    public function color(string|array|bool|Closure|null $color): static
    {
        $this->color = $color;

        return $this;
    }

    /**
     * @param array<mixed>|Closure $colors
     */
    public function colors(array|Closure $colors): static
    {
        $this->color(function (Column $column, mixed $state) use ($colors) {
            $colors = $column->evaluate($colors);

            $color = null;

            foreach ($colors as $conditionalColor => $condition) {
                if (is_numeric($conditionalColor)) {
                    $color = $condition;
                } elseif ($condition instanceof Closure && $this->evaluateColorCondition($condition, $state)) {
                    $color = $conditionalColor;
                } elseif ($condition === $state) {
                    $color = $conditionalColor;
                }
            }

            return $color;
        });

        return $this;
    }

    /**
     * @return string|array<int|string, string|int>|null
     */
    public function getColor(mixed $state): string|array|null
    {
        $color = $this->evaluate($this->color, [
            'state' => $state,
        ]);

        if ($color === true) {
            throw new LogicException(sprintf('Color of column [%s] must be a palette name, a shade map, or false; true given.', $this->getName()));
        }

        if ($color === false) {
            return null;
        }

        if (filled($color)) {
            return $color;
        }

        if (! $state instanceof ColorContract) {
            return null;
        }

        return $state->getColor();
    }

    protected function evaluateColorCondition(Closure $condition, mixed $state): mixed
    {
        return $this->evaluate($condition, [
            'state' => $state,
        ]);
    }
}
