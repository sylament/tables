<?php

declare(strict_types=1);

namespace Sylament\Tables\Table\Concerns;

use Closure;

use function Sylament\Support\wrap;

trait HasRecordClasses
{
    /**
     * @var array<mixed>|string|Closure|null
     */
    protected array|string|Closure|null $recordClasses = null;

    /**
     * @param array<mixed>|string|Closure|null $classes
     */
    public function recordClasses(array|string|Closure|null $classes): static
    {
        $this->recordClasses = $classes;

        return $this;
    }

    /**
     * A string, a list or a conditional map — whatever `classes()` flattens, in
     * the row's class list after ours, so a record class appends rather than
     * clobbers.
     *
     * @param object|array<string, mixed> $record
     * @return array<mixed>
     */
    public function getRecordClasses(object|array $record): array
    {
        return wrap($this->evaluate(
            $this->recordClasses,
            ['record' => $record],
            is_object($record) ? [$record::class => $record] : [],
        ) ?? []);
    }
}
