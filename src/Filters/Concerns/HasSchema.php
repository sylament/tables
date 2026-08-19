<?php

declare(strict_types=1);

namespace Sylament\Tables\Filters\Concerns;

use Closure;
use LogicException;
use Sylament\Actions\Action;
use Sylament\Actions\ActionGroup;
use Sylament\Forms\Components\Field;
use Sylament\Schemas\Components\Component;
use Sylament\Schemas\Schema;

trait HasSchema
{
    /**
     * @var array<Component|Action|ActionGroup>|Closure|null
     */
    protected array|Closure|null $schema = null;

    protected Field|Closure|null $modifyFormFieldUsing = null;

    /**
     * @param array<Component|Action|ActionGroup>|Closure|null $schema
     */
    public function schema(array|Closure|null $schema): static
    {
        $this->schema = $schema;

        return $this;
    }

    public function modifyFormFieldUsing(?Closure $callback): static
    {
        $this->modifyFormFieldUsing = $callback;

        return $this;
    }

    /**
     * An explicit schema wins outright — the field modifier is not consulted.
     *
     * @return array<Component|Action|ActionGroup>
     */
    public function getSchemaComponents(): array
    {
        $schema = $this->evaluate($this->schema);

        if ($schema !== null) {
            return $schema;
        }

        $field = $this->getFormField();

        if (!$field instanceof Field) {
            return [];
        }

        return [
            $this->evaluate(
                $this->modifyFormFieldUsing,
                namedInjections: ['field' => $field],
                typedInjections: [
                    Component::class => $field,
                    Field::class => $field,
                    $field::class => $field,
                ],
            ) ?? $field,
        ];
    }

    public function hasSchema(): bool
    {
        return $this->evaluate($this->schema) !== null;
    }

    /**
     * The live child schema this filter's group owns inside the host's filters form.
     */
    public function getSchema(): Schema
    {
        $form = $this->getLiveComponent()->getTableFiltersForm();

        foreach ($form->getComponents(withActions: false) as $component) {
            if ($component->getKey(isAbsolute: false) !== $this->getName()) {
                continue;
            }

            $childSchema = $component->getChildSchema();

            if ($childSchema instanceof Schema) {
                return $childSchema;
            }
        }

        throw new LogicException(sprintf('Filter [%s] has no group in the filters form.', $this->getName()));
    }

    public function getFormField(): ?Field
    {
        return null;
    }
}
