<?php

declare(strict_types=1);

namespace Sylament\Tables\Columns\Concerns;

use Closure;
use Sylament\Support\Twig\ComponentAttributeBag;

trait HasExtraHeaderAttributes
{
    /**
     * @var array<array<mixed>|Closure>
     */
    protected array $extraHeaderAttributes = [];

    /**
     * @param array<mixed>|Closure $attributes
     */
    public function extraHeaderAttributes(array|Closure $attributes, bool $merge = false): static
    {
        // Security: Attribute values are not escaped when rendered. Never
        // pass unsanitized user input as attribute names or values.

        if ($merge) {
            $this->extraHeaderAttributes[] = $attributes;
        } else {
            $this->extraHeaderAttributes = [$attributes];
        }

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function getExtraHeaderAttributes(): array
    {
        $attributeBag = new ComponentAttributeBag();

        foreach ($this->extraHeaderAttributes as $extraHeaderAttributes) {
            $attributeBag = $attributeBag->merge($this->evaluate($extraHeaderAttributes) ?: []);
        }

        return $attributeBag->getAttributes();
    }
}
