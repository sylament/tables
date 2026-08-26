<?php

declare(strict_types=1);

namespace Sylament\Tables\Table\Concerns;

use Closure;
use Stringable;
use Sylament\Support\Pagination\Paginator;
use Symfony\Component\PropertyAccess\Exception\AccessException;

use function Sylament\Support\filled;
use function Sylament\Support\get_record_label;
use function Sylament\Support\plural;

trait HasRecords
{
    protected ?Closure $dataSource = null;

    protected string|Closure|null $recordLabel = null;

    protected string|Closure|null $pluralRecordLabel = null;

    protected string|Closure|null $recordTitle = null;

    protected string|Closure|null $recordTitleAttribute = null;

    /**
     * @var class-string|null
     */
    protected ?string $cachedRecordClass = null;

    protected bool $hasCachedRecordClass = false;

    public function records(?Closure $dataSource): static
    {
        $this->dataSource = $dataSource;

        return $this;
    }

    public function getDataSource(): ?Closure
    {
        return $this->dataSource;
    }

    public function recordLabel(string|Closure|null $label): static
    {
        $this->recordLabel = $label;

        return $this;
    }

    public function pluralRecordLabel(string|Closure|null $label): static
    {
        $this->pluralRecordLabel = $label;

        return $this;
    }

    public function recordTitle(string|Closure|null $title): static
    {
        $this->recordTitle = $title;

        return $this;
    }

    public function recordTitleAttribute(string|Closure|null $attribute): static
    {
        $this->recordTitleAttribute = $attribute;

        return $this;
    }

    public function getRecordLabel(): string
    {
        $label = $this->evaluate($this->recordLabel);

        if (filled($label)) {
            return $label;
        }

        $class = $this->getRecordClass();

        return $class === null ? 'record' : get_record_label($class);
    }

    public function getPluralRecordLabel(): string
    {
        $label = $this->evaluate($this->pluralRecordLabel);

        return filled($label) ? $label : plural($this->getRecordLabel());
    }

    /**
     * The closure first, with the record injected by name and by type; then the
     * title attribute read through the accessor — a missing property, a blank or
     * a non-scalar answers the label instead, the way panels' Resource reads it.
     */
    public function getRecordTitle(object $record): string
    {
        $title = $this->evaluate($this->recordTitle, ['record' => $record], [$record::class => $record]);

        if (filled($title)) {
            return $title;
        }

        $attribute = $this->getRecordTitleAttribute();

        if ($attribute === null) {
            return $this->getRecordLabel();
        }

        try {
            $value = $this->getPropertyAccessor()->getFromRecord($record, $attribute);
        } catch (AccessException) {
            return $this->getRecordLabel();
        }

        if ($value instanceof Stringable) {
            $value = (string) $value;
        }

        $value = is_scalar($value) ? (string) $value : null;

        return filled($value) ? $value : $this->getRecordLabel();
    }

    public function hasCustomRecordTitle(): bool
    {
        return $this->recordTitle !== null;
    }

    public function getRecordTitleAttribute(): ?string
    {
        return $this->evaluate($this->recordTitleAttribute);
    }

    /**
     * Whether the table is on the query lane — a records closure wins over a
     * declared query, so this asks only whether one is set.
     */
    public function hasQuery(): bool
    {
        return $this->dataSource === null;
    }

    /**
     * @return class-string|null
     */
    public function getRecordClass(): ?string
    {
        if ($this->hasCachedRecordClass) {
            return $this->cachedRecordClass;
        }

        $this->hasCachedRecordClass = true;

        return $this->cachedRecordClass = array_first($this->getBaseQuery()?->getRootEntities() ?? []);
    }

    /**
     * The current page's records on a paginated table — the counts around them
     * are `getPaginator()`.
     *
     * @return array<array-key, object|array<string, mixed>> keyed by record key
     */
    public function getRecords(): array
    {
        $records = $this->getLiveComponent()->getTableRecords();

        return $records instanceof Paginator ? $records->getRecords() : $records;
    }

    public function getPaginator(): ?Paginator
    {
        return $this->getLiveComponent()->getTablePaginator();
    }

    /**
     * @param object|array<string, mixed> $record
     */
    public function getRecordKey(object|array $record): string
    {
        return $this->getLiveComponent()->getTableRecordKey($record);
    }
}
