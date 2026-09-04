<?php

declare(strict_types=1);

namespace Sylament\Tables\Table\Concerns;

use Sylament\Actions\Action;
use Sylament\Tables\Columns\Column;

trait HasColumns
{
    /**
     * @var array<string, Column>
     */
    protected array $columns = [];

    /**
     * @var array<string, Column>|null
     */
    protected ?array $cachedVisibleColumns = null;

    /**
     * @param array<Column> $columns
     */
    public function columns(array $columns): static
    {
        $this->columns = [];
        $this->cachedVisibleColumns = null;

        foreach ($columns as $column) {
            $column->table($this);

            $this->columns[$column->getName()] = $column;
        }

        foreach ($this->columns as $column) {
            $action = $column->getAction();

            if (!$action instanceof Action) {
                continue;
            }

            $this->cacheAction($action->table($this));
        }

        return $this;
    }

    /**
     * @return array<string, Column>
     */
    public function getColumns(): array
    {
        return $this->columns;
    }

    /**
     * The columns the table renders — the developer's visibility first, the
     * user's manager choice second. Memoised: every render walks it three
     * times and the toggle half hops through the host.
     *
     * @return array<string, Column>
     */
    public function getVisibleColumns(): array
    {
        return $this->cachedVisibleColumns ??= array_filter(
            $this->getColumns(),
            static fn (Column $column): bool => $column->isVisible() && !$column->isToggledHidden(),
        );
    }

    public function flushCachedVisibleColumns(): void
    {
        $this->cachedVisibleColumns = null;
    }

    public function getColumn(string $name): ?Column
    {
        return $this->getColumns()[$name] ?? null;
    }
}
