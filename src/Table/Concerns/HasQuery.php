<?php

declare(strict_types=1);

namespace Sylament\Tables\Table\Concerns;

use Closure;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Sylament\Schemas\State\Relationship;

trait HasQuery
{
    protected QueryBuilder|Closure|null $query = null;

    /**
     * @var array<QueryBuilder|Closure|null>
     */
    protected array $queryScopes = [];

    protected Relationship|Closure|null $getRelationshipUsing = null;

    protected ?QueryBuilder $cachedBaseQuery = null;

    protected bool $baseQueryIsRelationship = false;

    protected ?Relationship $cachedRelationship = null;

    protected bool $hasCachedRelationship = false;

    public function query(QueryBuilder|Closure|null $query): static
    {
        $this->query = $query;
        $this->cachedBaseQuery = null;
        $this->cachedRecordClass = null;
        $this->hasCachedRecordClass = false;

        return $this;
    }

    public function relationship(?Closure $relationship): static
    {
        $this->getRelationshipUsing = $relationship;
        $this->cachedBaseQuery = null;
        $this->cachedRecordClass = null;
        $this->hasCachedRecordClass = false;
        $this->cachedRelationship = null;
        $this->hasCachedRelationship = false;

        return $this;
    }

    /**
     * The owner record's association this table lists, evaluated once — the
     * closure usually resolves the owner, which is a database read.
     */
    public function getRelationship(): ?Relationship
    {
        if ($this->hasCachedRelationship) {
            return $this->cachedRelationship;
        }

        $this->hasCachedRelationship = true;

        return $this->cachedRelationship = $this->evaluate($this->getRelationshipUsing);
    }

    /**
     * The related records as a builder rooted at the target entity — the base
     * query a table with a relationship and no query() runs on.
     */
    public function getRelationshipQuery(): ?QueryBuilder
    {
        $relationship = $this->getRelationship();

        return $relationship instanceof Relationship ? $this->getAssociationReader()->createRelationshipQuery($relationship) : null;
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

    /**
     * Whether the relationship is the lane actually in force — a declared
     * `query()` wins over one, so a table carrying both runs on the query and
     * its relationship never becomes the base query.
     */
    public function isRelationshipBaseQuery(): bool
    {
        $this->getBaseQuery();

        return $this->baseQueryIsRelationship;
    }

    protected function getBaseQuery(): ?QueryBuilder
    {
        if ($this->cachedBaseQuery instanceof QueryBuilder) {
            return $this->cachedBaseQuery;
        }

        $query = $this->evaluate($this->query);

        if ($query instanceof QueryBuilder) {
            $this->baseQueryIsRelationship = false;

            return $this->cachedBaseQuery = $query;
        }

        $relationshipQuery = $this->getRelationshipQuery();
        $this->baseQueryIsRelationship = $relationshipQuery instanceof QueryBuilder;

        return $this->cachedBaseQuery = $relationshipQuery;
    }
}
