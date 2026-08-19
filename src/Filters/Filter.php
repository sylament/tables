<?php

declare(strict_types=1);

namespace Sylament\Tables\Filters;

use Sylament\Forms\Components\Checkbox;
use Sylament\Forms\Components\Field;
use Sylament\Forms\Components\Toggle;

use function Sylament\Support\filled;

class Filter extends BaseFilter
{
    /**
     * @var class-string<Field>
     */
    protected string $formComponent = Checkbox::class;

    protected function setUp(): void
    {
        parent::setUp();

        $this->indicateUsing(function (array $state): array {
            if (!($state['isActive'] ?? false)) {
                return [];
            }

            $indicator = $this->getIndicator();

            if (!$indicator instanceof Indicator) {
                $indicator = Indicator::make($indicator);
            }

            return [$indicator];
        });
    }

    public function toggle(): static
    {
        return $this->formComponent(Toggle::class);
    }

    public function checkbox(): static
    {
        return $this->formComponent(Checkbox::class);
    }

    /**
     * @param class-string<Field> $component
     */
    public function formComponent(string $component): static
    {
        $this->formComponent = $component;

        return $this;
    }

    public function getFormField(): Field
    {
        $field = $this->formComponent::make('isActive')->label($this->getLabel());

        $defaultState = $this->getDefaultState();

        if (filled($defaultState)) {
            $field->default($defaultState);
        }

        return $field;
    }

    /**
     * @return array<string, mixed>
     */
    public function getResetState(): array
    {
        if ($this->hasSchema()) {
            return parent::getResetState();
        }

        return ['isActive' => false];
    }
}
