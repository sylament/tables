<?php

declare(strict_types=1);

namespace Sylament\Tables\Columns\Concerns;

use Closure;
use Sylament\Support\Twig\ComponentAttributeBag;

trait HasExtraCellAttributes
{
    /**
     * @var array<array<mixed>|Closure>
     */
    protected array $extraCellAttributes = [];

    /**
     * @param array<mixed>|Closure $attributes
     */
    public function extraCellAttributes(array|Closure $attributes, bool $merge = false): static
    {
        // Security: Attribute values are not escaped when rendered. Never
        // pass unsanitized user input as attribute names or values.

        if ($merge) {
            $this->extraCellAttributes[] = $attributes;
        } else {
            $this->extraCellAttributes = [$attributes];
        }

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function getExtraCellAttributes(): array
    {
        $attributeBag = new ComponentAttributeBag();

        foreach ($this->extraCellAttributes as $extraCellAttributes) {
            $attributeBag = $attributeBag->merge($this->evaluate($extraCellAttributes) ?: []);
        }

        return $attributeBag->getAttributes();
    }
}
