<?php

declare(strict_types=1);

namespace Sylament\Tables\Columns\Summarizers\Concerns;

use Closure;
use Doctrine\ORM\QueryBuilder;

trait InteractsWithTableQuery
{
    protected ?Closure $modifyQueryUsing = null;

    protected ?QueryBuilder $query = null;

    /**
     * One setter with two slots, told apart by type: a builder points the summarizer
     * at the dataset being summarized — which the band does again on every render —
     * while a closure scopes that dataset. Null clears both.
     */
    public function query(QueryBuilder|Closure|null $query): static
    {
        if (!$query instanceof Closure) {
            $this->query = $query;
        }

        if (!$query instanceof QueryBuilder) {
            $this->modifyQueryUsing = $query;
        }

        return $this;
    }

    public function getQuery(): ?QueryBuilder
    {
        return $this->query;
    }

    /**
     * Whether the summarizer scopes the dataset itself, which is what keeps it out
     * of the batched select: a scoped figure is not the one the batch reads.
     */
    public function hasQueryModification(): bool
    {
        return $this->modifyQueryUsing instanceof Closure;
    }

    /**
     * Whatever the scope closure returned — the builder it narrowed, or nothing
     * where it narrowed the one it was handed in place.
     */
    protected function evaluateQueryModification(QueryBuilder $query, string $attribute): mixed
    {
        return $this->evaluate($this->modifyQueryUsing, [
            'attribute' => $attribute,
            'query' => $query,
        ]);
    }
}
