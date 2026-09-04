<?php

declare(strict_types=1);

namespace Sylament\Tables\Columns\Concerns;

use Closure;
use Doctrine\Common\Collections\Collection;
use Symfony\Component\PropertyAccess\Exception\AccessException;

use function Sylament\Support\blank;

trait HasCellState
{
    protected mixed $getStateUsing = null;

    protected mixed $defaultState = null;

    protected string|Closure|null $separator = null;

    protected bool|Closure $isDistinctList = false;

    /**
     * @var array<string, mixed>
     */
    protected array $cachedState = [];

    protected ?bool $cachedPathTraversesToMany = null;

    public function getStateUsing(mixed $callback): static
    {
        $this->getStateUsing = $callback;

        return $this;
    }

    public function state(mixed $state): static
    {
        return $this->getStateUsing($state);
    }

    public function default(mixed $state): static
    {
        $this->defaultState = $state;

        return $this;
    }

    public function getDefaultState(): mixed
    {
        return $this->evaluate($this->defaultState);
    }

    public function separator(string|Closure|null $separator = ','): static
    {
        $this->separator = $separator;

        return $this;
    }

    public function getSeparator(): ?string
    {
        return $this->evaluate($this->separator);
    }

    public function distinctList(bool|Closure $condition = true): static
    {
        $this->isDistinctList = $condition;

        return $this;
    }

    public function isDistinctList(): bool
    {
        return (bool) $this->evaluate($this->isDistinctList);
    }

    public function getState(): mixed
    {
        $recordKey = $this->getRecordKey();

        if ($recordKey === null) {
            return $this->resolveState();
        }

        if (array_key_exists($recordKey, $this->cachedState)) {
            return $this->cachedState[$recordKey];
        }

        return $this->cachedState[$recordKey] = $this->resolveState();
    }

    protected function resolveState(): mixed
    {
        $state = ($this->getStateUsing !== null)
            ? $this->evaluate($this->getStateUsing)
            : $this->getStateFromRecord();

        if (is_string($state) && ($separator = $this->getSeparator())) {
            $parts = explode($separator, $state);
            $state = count($parts) === 1 && mb_trim($parts[0]) === '' ? [] : $parts;
        }

        if (blank($state)) {
            return $this->getDefaultState();
        }

        return $state;
    }

    /**
     * The batched value first, for a column whose aggregate the table already
     * read for this page — the record carries no such property to walk to.
     *
     * Otherwise a single value for a name the record answers directly and for a
     * chain of to-one hops; a list — blanks dropped, `distinctList()` applied —
     * for a path that fans out over a collection, so a to-many column reads the
     * same shape on every row, one match or none included.
     */
    public function getStateFromRecord(): mixed
    {
        $table = isset($this->table) ? $this->getTable() : null;

        if ($table?->hasAggregateValuesFor($this->getName()) === true) {
            return $table->getAggregateValue($this->getName(), (string) $this->getRecordKey());
        }

        $record = $this->getRecord();

        if ($record === null) {
            return null;
        }

        $name = $this->getName();

        if (is_array($record)) {
            return $this->getPropertyAccessor()->getFromArray($record, $name);
        }

        $segments = explode('.', $name);

        if (count($segments) === 1) {
            try {
                return $this->getPropertyAccessor()->getFromRecord($record, $name);
            } catch (AccessException) {
                return null;
            }
        }

        [$values, $reachedCollection] = $this->walkRecordPath($record, $segments);

        if (!$reachedCollection && !$this->pathTraversesToMany()) {
            return $values[0] ?? null;
        }

        $values = array_values(array_filter($values, static fn (mixed $value): bool => !blank($value)));

        if ($this->isDistinctList()) {
            $values = array_values(array_unique($values, SORT_REGULAR));
        }

        return $values === [] ? null : $values;
    }

    public function clearCachedState(): void
    {
        $this->cachedState = [];
    }

    /**
     * Every value the path reaches, depth-first — one entry for a walk of
     * to-one hops, one per item for each collection crossed — paired with
     * whether a collection was crossed at all. A null or scalar met before the
     * path ends contributes nothing, and neither does a segment the record
     * cannot answer.
     *
     * @param list<string> $segments
     *
     * @return array{list<mixed>, bool}
     */
    private function walkRecordPath(mixed $current, array $segments): array
    {
        foreach ($segments as $index => $segment) {
            if ($current instanceof Collection) {
                $values = [];

                foreach ($current as $item) {
                    [$itemValues] = $this->walkRecordPath($item, array_slice($segments, $index));

                    $values = [...$values, ...$itemValues];
                }

                return [$values, true];
            }

            if (is_object($current)) {
                try {
                    $current = $this->getPropertyAccessor()->getFromRecord($current, $segment);
                } catch (AccessException) {
                    return [[], false];
                }

                continue;
            }

            if (!is_array($current)) {
                return [[], false];
            }

            $current = $current[$segment] ?? null;
        }

        if ($current instanceof Collection) {
            return [array_values($current->toArray()), true];
        }

        return [[$current], false];
    }

    /**
     * Whether the mapping puts a collection on the path, which the values of a
     * single row cannot say — a row whose to-one hop is null reaches no
     * collection and would otherwise read as a to-one cell. False on the
     * records lane, where there is no mapping to ask and the values decide.
     */
    private function pathTraversesToMany(): bool
    {
        if ($this->cachedPathTraversesToMany !== null) {
            return $this->cachedPathTraversesToMany;
        }

        $table = isset($this->table) ? $this->getTable() : null;
        $entityManager = $table?->getQueryEntityManager();
        $currentClass = $table?->getRecordClass();

        if ($entityManager === null || $currentClass === null) {
            return $this->cachedPathTraversesToMany = false;
        }

        foreach (explode('.', $this->getName()) as $segment) {
            $metadata = $entityManager->getClassMetadata($currentClass);

            if (!$metadata->hasAssociation($segment)) {
                break;
            }

            if ($metadata->isCollectionValuedAssociation($segment)) {
                return $this->cachedPathTraversesToMany = true;
            }

            $currentClass = $metadata->getAssociationTargetClass($segment);
        }

        return $this->cachedPathTraversesToMany = false;
    }
}
