<?php

declare(strict_types=1);

namespace Sylament\Tables\Table\Concerns;

use Closure;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;

trait HasQuery
{
    protected QueryBuilder|Closure|null $query = null;

    /**
     * @var array<QueryBuilder|Closure|null>
     */
    protected array $queryScopes = [];

    protected ?QueryBuilder $cachedBaseQuery = null;

    public function query(QueryBuilder|Closure|null $query): static
    {
        $this->query = $query;
        $this->cachedBaseQuery = null;
        $this->cachedRecordClass = null;
        $this->hasCachedRecordClass = false;

        return $this;
    }

    public function modifyQueryUsing(Closure $callback): static
    {
        $this->queryScopes[] = $callback;

        return $this;
    }

    public function applyQueryScopes(QueryBuilder $query, bool $isResolvingRecord = false): QueryBuilder
    {
        foreach ($this->queryScopes as $scope) {
            $query = $this->evaluate($scope, [
                'isResolvingRecord' => $isResolvingRecord,
                'query' => $query,
            ]) ?? $query;
        }

        return $query;
    }

    /**
     * A scoped clone of the declared query — never the stored builder, so a
     * caller may mutate what it gets back.
     */
    public function getQuery(bool $isResolvingRecord = false): ?QueryBuilder
    {
        $baseQuery = $this->getBaseQuery();

        if (!$baseQuery instanceof QueryBuilder) {
            return null;
        }

        return $this->applyQueryScopes(clone $baseQuery, $isResolvingRecord);
    }

    public function getQueryEntityManager(): ?EntityManagerInterface
    {
        return $this->getBaseQuery()?->getEntityManager();
    }

    protected function getBaseQuery(): ?QueryBuilder
    {
        return $this->cachedBaseQuery ??= $this->evaluate($this->query);
    }
}
