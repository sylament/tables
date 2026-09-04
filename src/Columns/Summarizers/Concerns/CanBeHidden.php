<?php

declare(strict_types=1);

namespace Sylament\Tables\Columns\Summarizers\Concerns;

use Closure;
use Doctrine\ORM\QueryBuilder;
use Sylament\Tables\Contracts\InteractsWithTable;

use function Sylament\Support\wrap;

trait CanBeHidden
{
    protected bool|Closure $isHidden = false;

    protected bool|Closure $isVisible = true;

    /**
     * @var array<string, bool>
     */
    protected array $visibilityCache = [];

    public function hidden(bool|Closure $condition = true): static
    {
        $this->isHidden = $condition;

        return $this;
    }

    /**
     * @param string|array<string> $hosts
     */
    public function hiddenOn(string|array $hosts): static
    {
        return $this->hidden(static fn (InteractsWithTable $liveComponent): bool => array_any(wrap($hosts), fn (string $host): bool => $liveComponent instanceof $host));
    }

    public function visible(bool|Closure $condition = true): static
    {
        $this->isVisible = $condition;

        return $this;
    }

    /**
     * @param string|array<string> $hosts
     */
    public function visibleOn(string|array $hosts): static
    {
        return $this->visible(static fn (InteractsWithTable $liveComponent): bool => array_any(wrap($hosts), fn (string $host): bool => $liveComponent instanceof $host));
    }

    /**
     * Cached per query rather than per summarizer: the documented predicate reads
     * the database (`hidden(fn ($query) => …)`), and one summarizer answers for the
     * page query, the all-table query and one query per group in a single render.
     */
    public function isHidden(): bool
    {
        $key = $this->visibilityCacheKey();
        $cached = $this->visibilityCache[$key] ?? null;

        if ($cached !== null) {
            return $cached;
        }

        if ($this->evaluate($this->isHidden)) {
            return $this->visibilityCache[$key] = true;
        }

        return $this->visibilityCache[$key] = !$this->evaluate($this->isVisible);
    }

    public function isVisible(): bool
    {
        return !$this->isHidden();
    }

    /**
     * A builder's identity is its DQL plus what it binds and how far it pages —
     * Doctrine's answer to the raw SQL Filament hashes. Object parameter values are
     * keyed by instance, since a record bound as a parameter is what a relationship
     * query carries and serializing one would walk its whole graph.
     */
    private function visibilityCacheKey(): string
    {
        $query = $this->getQuery();

        if (!$query instanceof QueryBuilder) {
            return '';
        }

        $parameters = [];

        foreach ($query->getParameters() as $parameter) {
            $parameters[$parameter->getName()] = self::identifyParameterValue($parameter->getValue());
        }

        ksort($parameters);

        return md5($query->getDQL() . '|' . serialize($parameters) . '|' . $query->getFirstResult() . '|' . $query->getMaxResults());
    }

    private static function identifyParameterValue(mixed $value): mixed
    {
        if (is_object($value)) {
            return spl_object_id($value);
        }

        if (is_array($value)) {
            return array_map(self::identifyParameterValue(...), $value);
        }

        return $value;
    }
}
