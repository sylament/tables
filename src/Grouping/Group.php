<?php

declare(strict_types=1);

namespace Sylament\Tables\Grouping;

use BackedEnum;
use Closure;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\QueryBuilder;
use LogicException;
use Stringable;
use Sylament\Schemas\Components\Concerns\CanAccessProperties;
use Sylament\Support\Components\Component;
use Sylament\Support\Contracts\HasLabel;
use Sylament\Support\Evaluation\ClosureParameter;
use Sylament\Tables\Grouping\Concerns\BelongsToTable;
use Sylament\Tables\Query\ColumnPathResolver;
use Sylament\Tables\Query\Hop;
use Sylament\Tables\Query\RelationJoiner;
use Sylament\Tables\Query\RelationPathResolver;
use Sylament\Tables\Query\ResolvedRelationPath;
use Symfony\Component\PropertyAccess\Exception\AccessException;
use Twig\Markup;

use function Sylament\Support\blank;
use function Symfony\Component\String\u;

/**
 * One way of grouping a table's records: which value the rows are keyed by, how
 * that key reads as a heading, and how a query is ordered and narrowed by it.
 * A group renders nothing of its own — every pixel of the header row is the
 * table partial's.
 */
class Group extends Component
{
    use BelongsToTable;
    use CanAccessProperties;

    protected ?Closure $getDescriptionFromRecordUsing = null;

    protected ?Closure $getKeyFromRecordUsing = null;

    protected ?Closure $getTitleFromRecordUsing = null;

    protected ?Closure $orderQueryUsing = null;

    protected ?Closure $scopeQueryUsing = null;

    protected ?Closure $scopeQueryByKeyUsing = null;

    protected string|Markup|Closure|null $label = null;

    protected string $id;

    protected ?string $column = null;

    protected bool $isCollapsible = false;

    protected bool $isTitlePrefixedWithLabel = true;

    protected bool $isDate = false;

    /**
     * Resolved titles by string key, the blank key sharing the `''` slot.
     *
     * @var array<string, string|Markup|null>
     */
    protected array $cachedTitles = [];

    protected string $evaluationIdentifier = 'group';

    public static function make(?string $id = null): static
    {
        if (blank($id)) {
            throw new LogicException(sprintf('Group of class [%s] must have a unique id, passed to the [make()] method.', static::class));
        }

        $group = static::newInstance();

        $group->id($id);

        $group->configure();

        return $group;
    }

    public function collapsible(bool $condition = true): static
    {
        $this->isCollapsible = $condition;

        return $this;
    }

    public function column(?string $column): static
    {
        $this->column = $column;

        return $this;
    }

    public function date(bool $condition = true): static
    {
        $this->isDate = $condition;

        return $this;
    }

    /**
     * A colon is refused rather than accepted and lost: the grouping property
     * carries `<id>:<direction>` as one token and reads the id back off the first
     * colon, so an id holding one would resolve to the fragment before it — a group
     * the table does not offer, which degrades to no grouping in silence.
     */
    public function id(string $id): static
    {
        if (str_contains($id, ':')) {
            throw new LogicException(sprintf('Group of class [%s] has the id [%s], which must not contain a colon: the grouping state writes the id and its direction as one colon-separated token.', static::class, $id));
        }

        $this->id = $id;

        return $this;
    }

    public function label(string|Markup|Closure|null $label): static
    {
        $this->label = $label;

        return $this;
    }

    public function titlePrefixedWithLabel(bool $condition = true): static
    {
        $this->isTitlePrefixedWithLabel = $condition;

        return $this;
    }

    public function getDescriptionFromRecordUsing(?Closure $callback): static
    {
        $this->getDescriptionFromRecordUsing = $callback;

        return $this;
    }

    public function getKeyFromRecordUsing(?Closure $callback): static
    {
        $this->getKeyFromRecordUsing = $callback;

        return $this;
    }

    public function getTitleFromRecordUsing(?Closure $callback): static
    {
        $this->getTitleFromRecordUsing = $callback;

        return $this;
    }

    public function orderQueryUsing(?Closure $callback): static
    {
        $this->orderQueryUsing = $callback;

        return $this;
    }

    public function scopeQueryUsing(?Closure $callback): static
    {
        $this->scopeQueryUsing = $callback;

        return $this;
    }

    public function scopeQueryByKeyUsing(?Closure $callback): static
    {
        $this->scopeQueryByKeyUsing = $callback;

        return $this;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getColumn(): string
    {
        return $this->column ?? $this->getId();
    }

    public function isCollapsible(): bool
    {
        return $this->isCollapsible;
    }

    public function isTitlePrefixedWithLabel(): bool
    {
        return $this->isTitlePrefixedWithLabel;
    }

    public function isDate(): bool
    {
        return $this->isDate;
    }

    /**
     * The declared label, else the id's last relation segment spaced and
     * title-cased — so a dotted path names the relation it crosses, `artist.name`
     * reading *Artist*, which is Filament's own `beforeLast()`/`afterLast()` pair.
     */
    public function getLabel(): string|Markup
    {
        $label = $this->evaluate($this->label);

        if ($label !== null) {
            return $label;
        }

        return (string) u($this->getId())
            ->beforeLast('.')
            ->afterLast('.')
            ->kebab()
            ->replace('-', ' ')
            ->replace('_', ' ')
            ->title();
    }

    /**
     * The raw grouped value of one record — whatever the column holds, before
     * any of the coercions {@see getStringKey()} applies.
     *
     * @param object|array<string, mixed> $record
     */
    public function getKey(object|array $record): mixed
    {
        if ($this->getKeyFromRecordUsing instanceof Closure) {
            return $this->evaluateForRecord($this->getKeyFromRecordUsing, $record);
        }

        return $this->readColumn($record);
    }

    /**
     * The printable identity of the group a record belongs to, or null where the
     * value cannot name one. A backed enum answers its backing value; under
     * `date()` a date answers its day and everything else answers null, since a
     * `date()` group is declared over a mapped date field; a datetime outside
     * `date()` answers the instant as `Y-m-d H:i:s`, so such a column groups per
     * timestamp; and anything neither scalar nor `Stringable` — a collection, an
     * array, a pure enum — answers null rather than failing, which is what lets
     * one reader key both lanes.
     *
     * @param object|array<string, mixed> $record
     */
    public function getStringKey(object|array $record): ?string
    {
        $key = $this->getKey($record);

        if ($key instanceof BackedEnum) {
            $key = $key->value;
        }

        if ($this->isDate()) {
            return $key instanceof DateTimeInterface ? $key->format('Y-m-d') : null;
        }

        return $this->toStringKey($key);
    }

    /**
     * The heading of a record's group. Memoised by string key, since a page
     * renders one title per group and the read behind it can be a relation hop —
     * but never under a title closure, which is free to answer per record.
     *
     * @param object|array<string, mixed> $record
     */
    public function getTitle(object|array $record, ?string $key = null): string|Markup|null
    {
        if ($this->getTitleFromRecordUsing instanceof Closure) {
            return $this->resolveTitle($record);
        }

        $cacheKey = ($key ?? $this->getStringKey($record)) ?? '';

        if (!array_key_exists($cacheKey, $this->cachedTitles)) {
            $this->cachedTitles[$cacheKey] = $this->resolveTitle($record);
        }

        return $this->cachedTitles[$cacheKey];
    }

    /**
     * The second line of a group heading — null unless a description closure was
     * given, which is the only thing that produces one.
     *
     * @param object|array<string, mixed> $record
     */
    public function getDescription(object|array $record, string|Markup|null $title): string|Markup|null
    {
        if (!$this->getDescriptionFromRecordUsing instanceof Closure) {
            return null;
        }

        return $this->toRenderable($this->evaluateForRecord(
            $this->getDescriptionFromRecordUsing,
            $record,
            ['title' => $title],
        ));
    }

    /**
     * The group's own ordering, appended ahead of every sort the table adds
     * after it: a relation path left-joins its to-one hops and reads the field
     * off the alias, and a `date()` group orders by the raw column, so ties
     * within a day resolve by time and no SQL date function is needed.
     */
    public function orderQuery(QueryBuilder $query, string $direction): QueryBuilder
    {
        if ($this->orderQueryUsing instanceof Closure) {
            return $this->toScopedQuery($this->evaluate($this->orderQueryUsing, [
                'column' => $this->getColumn(),
                'direction' => $direction,
                'query' => $query,
            ]), $query);
        }

        return $query->addOrderBy(ColumnPathResolver::resolveOrderable($query, $this->getColumn()), $direction);
    }

    /**
     * @param object|array<string, mixed> $record
     */
    public function scopeQuery(QueryBuilder $query, object|array $record): QueryBuilder
    {
        if ($this->scopeQueryUsing instanceof Closure) {
            return $this->toScopedQuery($this->evaluateForRecord($this->scopeQueryUsing, $record, [
                'column' => $this->getColumn(),
                'query' => $query,
            ]), $query);
        }

        return $this->scopeQueryByKey($query, $this->getStringKey($record));
    }

    /**
     * Narrows a query to one group. Always `andWhere`, so whatever the caller
     * already constrained survives; a blank key is `IS NULL`, which over the
     * left join a relation path mints also admits the rows with no related row
     * at all; and a `date()` group becomes a half-open day range bound with the
     * resolved field's own DBAL type, since an untyped datetime literal compares
     * as text against a `date` column and answers the neighbouring day.
     */
    public function scopeQueryByKey(QueryBuilder $query, ?string $key): QueryBuilder
    {
        $column = $this->getColumn();

        if ($this->scopeQueryByKeyUsing instanceof Closure) {
            return $this->toScopedQuery($this->evaluate($this->scopeQueryByKeyUsing, [
                'column' => $column,
                'key' => $key,
                'query' => $query,
            ]), $query);
        }

        $path = ColumnPathResolver::resolveOrderable($query, $column);

        if (blank($key)) {
            return $query->andWhere("{$path} IS NULL");
        }

        if (!$this->isDate()) {
            $parameter = $this->mintParameter($query);

            return $query
                ->andWhere("{$path} = :{$parameter}")
                ->setParameter($parameter, $key);
        }

        $from = DateTimeImmutable::createFromFormat('!Y-m-d', $key);

        if ($from === false) {
            return $query->andWhere('1 = 0');
        }

        $type = $this->resolveFieldType($query, $column);
        $fromParameter = $this->mintParameter($query);
        $toParameter = $this->mintParameter($query, offset: 1);

        return $query
            ->andWhere("{$path} >= :{$fromParameter} AND {$path} < :{$toParameter}")
            ->setParameter($fromParameter, $from, $type)
            ->setParameter($toParameter, $from->modify('+1 day'), $type);
    }

    /**
     * Fetch-joins the relation the grouped column reads through, so the header
     * of every group on the page is answered from the page query.
     */
    public function applyEagerLoading(QueryBuilder $query): QueryBuilder
    {
        return RelationJoiner::fetchJoinPath($query, $this->getColumn());
    }

    /**
     * @param object|array<string, mixed> $record
     */
    protected function resolveTitle(object|array $record): string|Markup|null
    {
        $title = $this->getTitleFromRecordUsing instanceof Closure
            ? $this->evaluateForRecord($this->getTitleFromRecordUsing, $record)
            : $this->readColumn($record);

        if ($title instanceof HasLabel) {
            $title = $title->getLabel();
        }

        if ($title instanceof Markup) {
            return $title;
        }

        if ($this->isDate()) {
            return $title instanceof DateTimeInterface
                ? $title->format($this->getTable()->getDefaultDateDisplayFormat())
                : null;
        }

        return $this->toStringKey($title);
    }

    protected function resolveDefaultClosureDependencyForEvaluationByName(string $parameterName): ?ClosureParameter
    {
        return match ($parameterName) {
            'liveComponent' => ClosureParameter::make($this->getLiveComponent()),
            'table' => ClosureParameter::make($this->getTable()),
            default => parent::resolveDefaultClosureDependencyForEvaluationByName($parameterName),
        };
    }

    /**
     * The grouped column read straight off a record — by key on an array row, by
     * property path on an object one, where a path the record does not expose is
     * an absent value rather than a failure.
     *
     * @param object|array<string, mixed> $record
     */
    private function readColumn(object|array $record): mixed
    {
        $column = $this->getColumn();

        if (is_array($record)) {
            return $this->getPropertyAccessor()->getFromArray($record, $column);
        }

        try {
            return $this->getPropertyAccessor()->getFromRecord($record, $column);
        } catch (AccessException) {
            return null;
        }
    }

    /**
     * The record injections every per-record closure of this class shares —
     * `column` and `record` by name, the record by its own class.
     *
     * @param object|array<string, mixed> $record
     * @param array<string, mixed>        $namedInjections
     */
    private function evaluateForRecord(Closure $callback, object|array $record, array $namedInjections = []): mixed
    {
        return $this->evaluate(
            $callback,
            namedInjections: [
                'column' => $this->getColumn(),
                'record' => $record,
                ...$namedInjections,
            ],
            typedInjections: is_object($record) ? [$record::class => $record] : [],
        );
    }

    /**
     * The DBAL type of the field the column resolves to — the last hop's target
     * for a relation path, the query's own root entity otherwise, which is also
     * the dotless case, since a path with no dot is one the relation resolver
     * declines. Null where the segment names an association or the mapping
     * resolves nothing, which binds the value untyped.
     */
    private function resolveFieldType(QueryBuilder $query, string $column): ?string
    {
        $analysed = RelationPathResolver::tryAnalyseQueryPath($query, $column);
        $lastHop = $analysed instanceof ResolvedRelationPath ? array_last($analysed->hops) : null;

        $owningClass = $lastHop instanceof Hop
            ? $lastHop->targetClass
            : $query->getRootEntities()[0] ?? null;

        if ($owningClass === null) {
            return null;
        }

        $field = $analysed instanceof ResolvedRelationPath ? $analysed->fieldPath : $column;

        return $query->getEntityManager()->getClassMetadata($owningClass)->getTypeOfField($field);
    }

    /**
     * The namespaced, counter-suffixed parameter name the constraint binds
     * under — `setParameter()` overwrites a same-named parameter in silence, so
     * a group narrowing a query that already carries one must not collide with
     * it. The offset is for the second name of a pair, neither of which is bound
     * yet when both are minted.
     */
    private function mintParameter(QueryBuilder $query, int $offset = 0): string
    {
        return 'sylament_group_' . (count($query->getParameters()) + $offset);
    }

    /**
     * The printable form of a value, or null where it cannot name a group —
     * blank, or neither scalar nor `Stringable`. The guard
     * `HasTable::toRecordKey()` applies to a record key, copied because that one
     * is private to the host trait, and widened by two arms. A Doctrine
     * collection is `Stringable`, but what it prints is an identity hash rather
     * than a value, so a to-many column names no group instead of naming a
     * different one per row. A `DateTimeInterface` is neither scalar nor
     * `Stringable` where Eloquent's Carbon prints itself, so it is formatted as
     * the same `Y-m-d H:i:s` string vendor keys by — which is also the literal
     * the equality scope binds.
     */
    private function toStringKey(mixed $value): ?string
    {
        if ($value instanceof Collection) {
            return null;
        }

        if ($value instanceof DateTimeInterface) {
            $value = $value->format('Y-m-d H:i:s');
        }

        if ($value instanceof Stringable) {
            $value = (string) $value;
        }

        if (blank($value) || !is_scalar($value)) {
            return null;
        }

        return (string) $value;
    }

    /**
     * What a query closure narrowed to: the builder it returned, else the one it
     * was handed — Filament's `?? $query`, typed, since a closure that mutates
     * the builder in place returns nothing at all.
     */
    private function toScopedQuery(mixed $returned, QueryBuilder $query): QueryBuilder
    {
        return $returned instanceof QueryBuilder ? $returned : $query;
    }

    private function toRenderable(mixed $value): string|Markup|null
    {
        return $value instanceof Markup ? $value : $this->toStringKey($value);
    }
}
