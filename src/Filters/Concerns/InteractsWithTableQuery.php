<?php

declare(strict_types=1);

namespace Sylament\Tables\Filters\Concerns;

use Closure;
use Doctrine\ORM\QueryBuilder;

trait InteractsWithTableQuery
{
    protected ?Closure $modifyQueryUsing = null;

    protected ?Closure $modifyBaseQueryUsing = null;

    protected bool|Closure $shouldExcludeWhenResolvingRecord = false;

    /**
     * The builder is mutated in place; the callback's return value is discarded.
     *
     * @param array<string, mixed> $data
     */
    public function apply(QueryBuilder $query, array $data = []): QueryBuilder
    {
        if (!$this->hasQueryModificationCallback()) {
            return $query;
        }

        if ($this->isHidden()) {
            return $query;
        }

        if (!(bool) ($data['isActive'] ?? true)) {
            return $query;
        }

        $this->evaluate($this->modifyQueryUsing, [
            'data' => $data,
            'query' => $query,
            'state' => $data,
        ]);

        return $query;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function applyToBaseQuery(QueryBuilder $query, array $data = []): QueryBuilder
    {
        if (!$this->hasBaseQueryModificationCallback()) {
            return $query;
        }

        if ($this->isHidden()) {
            return $query;
        }

        if (!(bool) ($data['isActive'] ?? true)) {
            return $query;
        }

        $this->evaluate($this->modifyBaseQueryUsing, [
            'data' => $data,
            'query' => $query,
            'state' => $data,
        ]);

        return $query;
    }

    public function query(?Closure $callback): static
    {
        return $this->modifyQueryUsing($callback);
    }

    public function baseQuery(?Closure $callback): static
    {
        return $this->modifyBaseQueryUsing($callback);
    }

    public function modifyQueryUsing(?Closure $callback): static
    {
        $this->modifyQueryUsing = $callback;

        return $this;
    }

    public function modifyBaseQueryUsing(?Closure $callback): static
    {
        $this->modifyBaseQueryUsing = $callback;

        return $this;
    }

    public function excludeWhenResolvingRecord(bool|Closure $condition = true): static
    {
        $this->shouldExcludeWhenResolvingRecord = $condition;

        return $this;
    }

    public function shouldExcludeWhenResolvingRecord(): bool
    {
        return (bool) $this->evaluate($this->shouldExcludeWhenResolvingRecord);
    }

    protected function hasQueryModificationCallback(): bool
    {
        return $this->modifyQueryUsing instanceof Closure;
    }

    protected function hasBaseQueryModificationCallback(): bool
    {
        return $this->modifyBaseQueryUsing instanceof Closure;
    }
}
