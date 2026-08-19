<?php

declare(strict_types=1);

namespace Sylament\Tables\Columns\Concerns;

use Closure;
use LogicException;
use Sylament\Tables\Query\AggregateDeclaration;
use Symfony\Component\String\UnicodeString;

trait CanAggregateRelatedRecords
{
    protected string|Closure|null $columnToAvg = null;

    /**
     * @var string|array<int|string, string|Closure>|Closure|null
     */
    protected string|array|Closure|null $relationshipToAvg = null;

    /**
     * @var string|array<int|string, string|Closure>|Closure|null
     */
    protected string|array|Closure|null $relationshipsToCount = null;

    /**
     * @var string|array<int|string, string|Closure>|Closure|null
     */
    protected string|array|Closure|null $relationshipsToExistenceCheck = null;

    protected string|Closure|null $columnToMax = null;

    /**
     * @var string|array<int|string, string|Closure>|Closure|null
     */
    protected string|array|Closure|null $relationshipToMax = null;

    protected string|Closure|null $columnToMin = null;

    /**
     * @var string|array<int|string, string|Closure>|Closure|null
     */
    protected string|array|Closure|null $relationshipToMin = null;

    protected string|Closure|null $columnToSum = null;

    /**
     * @var string|array<int|string, string|Closure>|Closure|null
     */
    protected string|array|Closure|null $relationshipToSum = null;

    /**
     * @param string|array<int|string, string|Closure>|Closure|null $relationship
     */
    public function avg(string|array|Closure|null $relationship, string|Closure|null $column): static
    {
        $this->columnToAvg = $column;
        $this->relationshipToAvg = $relationship;

        return $this;
    }

    /**
     * @param string|array<int|string, string|Closure>|Closure|null $relationships
     */
    public function counts(string|array|Closure|null $relationships): static
    {
        $this->relationshipsToCount = $relationships;

        return $this;
    }

    /**
     * @param string|array<int|string, string|Closure>|Closure|null $relationships
     */
    public function exists(string|array|Closure|null $relationships): static
    {
        $this->relationshipsToExistenceCheck = $relationships;

        return $this;
    }

    /**
     * @param string|array<int|string, string|Closure>|Closure|null $relationship
     */
    public function max(string|array|Closure|null $relationship, string|Closure|null $column): static
    {
        $this->columnToMax = $column;
        $this->relationshipToMax = $relationship;

        return $this;
    }

    /**
     * @param string|array<int|string, string|Closure>|Closure|null $relationship
     */
    public function min(string|array|Closure|null $relationship, string|Closure|null $column): static
    {
        $this->columnToMin = $column;
        $this->relationshipToMin = $relationship;

        return $this;
    }

    /**
     * @param string|array<int|string, string|Closure>|Closure|null $relationship
     */
    public function sum(string|array|Closure|null $relationship, string|Closure|null $column): static
    {
        $this->columnToSum = $column;
        $this->relationshipToSum = $relationship;

        return $this;
    }

    public function getColumnToAvg(): ?string
    {
        return $this->evaluate($this->columnToAvg);
    }

    /**
     * @return string|array<int|string, string|Closure>|null
     */
    public function getRelationshipToAvg(): string|array|null
    {
        return $this->evaluate($this->relationshipToAvg);
    }

    /**
     * @return string|array<int|string, string|Closure>|null
     */
    public function getRelationshipsToCount(): string|array|null
    {
        return $this->evaluate($this->relationshipsToCount);
    }

    /**
     * @return string|array<int|string, string|Closure>|null
     */
    public function getRelationshipsToExistenceCheck(): string|array|null
    {
        return $this->evaluate($this->relationshipsToExistenceCheck);
    }

    public function getColumnToMax(): ?string
    {
        return $this->evaluate($this->columnToMax);
    }

    /**
     * @return string|array<int|string, string|Closure>|null
     */
    public function getRelationshipToMax(): string|array|null
    {
        return $this->evaluate($this->relationshipToMax);
    }

    public function getColumnToMin(): ?string
    {
        return $this->evaluate($this->columnToMin);
    }

    /**
     * @return string|array<int|string, string|Closure>|null
     */
    public function getRelationshipToMin(): string|array|null
    {
        return $this->evaluate($this->relationshipToMin);
    }

    public function getColumnToSum(): ?string
    {
        return $this->evaluate($this->columnToSum);
    }

    /**
     * @return string|array<int|string, string|Closure>|null
     */
    public function getRelationshipToSum(): string|array|null
    {
        return $this->evaluate($this->relationshipToSum);
    }

    public function hasRelationshipAggregates(): bool
    {
        return array_any([
            $this->getColumnToAvg(),
            $this->getRelationshipToAvg(),
            $this->getRelationshipsToCount(),
            $this->getRelationshipsToExistenceCheck(),
            $this->getColumnToMax(),
            $this->getRelationshipToMax(),
            $this->getColumnToMin(),
            $this->getRelationshipToMin(),
            $this->getColumnToSum(),
            $this->getRelationshipToSum(),
        ], static fn (mixed $declared): bool => $declared !== null);
    }

    /**
     * The one declaration whose value this column's cell reads, or null when
     * none does.
     *
     * A kind claims the cell either by construction — the singular string form
     * binds to the column whatever it is named — or by name, when one of its
     * aliases (explicit through `relationship as alias`, otherwise derived the
     * way Eloquent derives it) is the column name. A kind that does neither is
     * inert here: the array form declares several aggregates at once and the
     * ones aliased elsewhere simply belong to other cells.
     *
     * More than one claim cannot fill a single cell, and the column name breaks
     * that tie only between claims of the same kind — two string-form
     * declarations are told apart by which one's derived alias is the column
     * name. A string-form claim beside an array entry deliberately aliased to
     * that same name is not a tie the name can break, so it refuses.
     *
     * @throws LogicException when more than one kind claims this cell and the
     *                        name cannot say which, always a misdeclaration
     */
    public function getCellAggregateDeclaration(): ?AggregateDeclaration
    {
        $name = $this->getName();

        $claims = $this->getAggregateClaims($name);

        if ($claims === []) {
            return null;
        }

        if (count($claims) === 1) {
            return $claims[0]['declaration'];
        }

        $named = array_values(array_filter($claims, static fn (array $claim): bool => $claim['isNamed']));

        if (count($named) === 1 && $named[0]['bindsToAnyName']) {
            return $named[0]['declaration'];
        }

        throw new LogicException(sprintf(
            'Column [%s] declares multiple aggregates claiming its cell, and its name cannot say which one fills it. Alias the one it reads [relationship as %s], or move the others onto columns of their own.',
            $name,
            $name,
        ));
    }

    /**
     * The declaration each declared kind puts forward for the cell named
     * $name, skipping the kinds that put forward none.
     *
     * @return list<array{declaration: AggregateDeclaration, isNamed: bool, bindsToAnyName: bool}>
     */
    private function getAggregateClaims(string $name): array
    {
        $claims = [];

        $groups = [
            $this->buildAggregateGroup($this->getRelationshipToAvg(), 'AVG', 'avg', $this->getColumnToAvg()),
            $this->buildAggregateGroup($this->getRelationshipsToCount(), 'COUNT', 'count'),
            $this->buildAggregateGroup($this->getRelationshipsToExistenceCheck(), 'COUNT', 'exists', castToBool: true),
            $this->buildAggregateGroup($this->getRelationshipToMax(), 'MAX', 'max', $this->getColumnToMax()),
            $this->buildAggregateGroup($this->getRelationshipToMin(), 'MIN', 'min', $this->getColumnToMin()),
            $this->buildAggregateGroup($this->getRelationshipToSum(), 'SUM', 'sum', $this->getColumnToSum()),
        ];

        foreach ($groups as $group) {
            if ($group['entries'] === []) {
                continue;
            }

            $named = null;

            foreach ($group['entries'] as $entry) {
                if ($entry['alias'] === $name) {
                    $named = $entry['declaration'];

                    break;
                }
            }

            if ($named === null && ! $group['bindsToAnyName']) {
                continue;
            }

            $claims[] = [
                'declaration' => $named ?? $group['entries'][0]['declaration'],
                'isNamed' => $named instanceof AggregateDeclaration,
                'bindsToAnyName' => $group['bindsToAnyName'],
            ];
        }

        return $claims;
    }

    /**
     * @param string|array<int|string, string|Closure>|null $relationships
     * @return array{bindsToAnyName: bool, entries: list<array{alias: string, declaration: AggregateDeclaration}>}
     */
    private function buildAggregateGroup(
        string|array|null $relationships,
        string $function,
        string $aliasSuffix,
        ?string $column = null,
        bool $castToBool = false,
    ): array {
        $bindsToAnyName = is_string($relationships);

        if ($relationships === null) {
            return ['bindsToAnyName' => $bindsToAnyName, 'entries' => []];
        }

        $entries = [];

        foreach (is_string($relationships) ? [$relationships] : $relationships as $key => $value) {
            $declared = is_string($key) ? $key : $value;
            $scope = is_string($key) ? $value : null;

            if (! is_string($declared) || ($scope !== null && ! $scope instanceof Closure)) {
                throw new LogicException(sprintf(
                    'Column [%s] declares a malformed aggregate: name the relationship as an array value, or as an array key whose value is the closure scoping it.',
                    $this->getName(),
                ));
            }

            [$relationshipPath, $alias] = self::parseAggregateRelationship($declared);

            $entries[] = [
                'alias' => $alias ?? self::deriveAggregateAlias($relationshipPath, $aliasSuffix, $column),
                'declaration' => new AggregateDeclaration(
                    function: $function,
                    relationshipPath: $relationshipPath,
                    column: $column,
                    scope: $scope,
                    castToBool: $castToBool,
                ),
            ];
        }

        return ['bindsToAnyName' => $bindsToAnyName, 'entries' => $entries];
    }

    /**
     * @return array{string, ?string} the relationship path, then the alias its
     *                                `as` clause gave it, or null for none
     */
    private static function parseAggregateRelationship(string $relationship): array
    {
        $segments = explode(' ', $relationship);

        if (count($segments) === 3 && mb_strtolower($segments[1]) === 'as') {
            return [$segments[0], $segments[2]];
        }

        return [$relationship, null];
    }

    private static function deriveAggregateAlias(string $relationshipPath, string $aliasSuffix, ?string $column): string
    {
        $alias = new UnicodeString($relationshipPath)->snake() . '_' . $aliasSuffix;

        if ($column === null) {
            return $alias;
        }

        return $alias . '_' . new UnicodeString($column)->snake();
    }
}
