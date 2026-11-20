<?php

declare(strict_types=1);

namespace Sylament\Tables\Testing;

use Closure;
use LogicException;
use PHPUnit\Framework\Assert;
use Sylament\Support\ArrayRecord;
use Sylament\Tables\Columns\Column;
use Sylament\Tables\Contracts\InteractsWithTable;

/**
 * @phpstan-require-extends Testable
 */
trait TestsColumns
{
    /**
     * @param (Closure(Column): bool)|null $checkColumnUsing
     */
    public function assertTableColumnExists(string $name, ?Closure $checkColumnUsing = null, mixed $record = null): static
    {
        $host = $this->getTestedTableHost();
        $column = $host->getTable()->getColumn($name);
        $hostClass = $host::class;

        Assert::assertInstanceOf(
            Column::class,
            $column,
            "Failed asserting that a table column with name [{$name}] exists on the [{$hostClass}] component.",
        );

        if ($record) {
            $recordKey = $this->getTestedTableColumnRecordKey($host, $record);

            $column->record($host->getTableRecord($recordKey)
                ?? throw new LogicException("The [{$hostClass}] table has no record with key [{$recordKey}]."));
        }

        if ($checkColumnUsing instanceof Closure) {
            Assert::assertTrue(
                $checkColumnUsing($column),
                "Failed asserting that a column with the name [{$name}] and provided configuration exists on the [{$hostClass}] component.",
            );
        }

        return $this;
    }

    /**
     * A record object is sent as the key the table host mints for it; anything else is
     * sent as the key it is.
     */
    public function callTableColumnAction(string $name, mixed $record = null): static
    {
        $this->assertTableColumnExists($name);

        if (is_object($record)) {
            $record = $this->getTestedTableHost()->getTableRecordKey($record);
        }

        $this->call('callTableColumnAction', ['name' => $name, 'recordKey' => $record]);

        return $this;
    }

    public function sortTable(?string $name = null, ?string $direction = null): static
    {
        $this->call('sortTable', ['column' => $name, 'direction' => $direction]);

        return $this;
    }

    public function searchTable(?string $search = null): static
    {
        $this->set('tableSearch', $search);

        return $this;
    }

    /**
     * @param array<string, mixed> $searches
     */
    public function searchTableColumns(array $searches): static
    {
        $this->set('tableColumnSearches', $searches);

        return $this;
    }

    /**
     * Flips every toggleable column of the applied column state, writes it as the column
     * manager's draft and applies it, as a user ticking every box and applying does.
     */
    public function toggleAllTableColumns(bool $condition = true): static
    {
        $tableColumns = [];

        foreach ($this->getTestedTableColumnState() as $column) {
            if ($column['isToggledHiddenByDefault'] !== null) {
                $column['isToggled'] = $condition;
            }

            $tableColumns[] = $column;
        }

        $this->set('tableDeferredColumns', $tableColumns);

        $this->call('applyTableColumnManager');

        return $this;
    }

    /**
     * A record object is keyed by the table host, as the row it renders is; an array
     * record by its `ArrayRecord` key; anything else is the key it is.
     */
    private function getTestedTableColumnRecordKey(InteractsWithTable $host, mixed $record): string
    {
        if (is_object($record)) {
            return $host->getTableRecordKey($record);
        }

        if (is_array($record)) {
            $record = $record[ArrayRecord::getKeyName()] ?? null;
        }

        if (!is_scalar($record)) {
            throw new LogicException(sprintf('A table record is an object, an array or a key; [%s] is none of them.', get_debug_type($record)));
        }

        return (string) $record;
    }

    /**
     * The applied state the re-hydrated host keeps, whose `isToggledHiddenByDefault` is
     * null exactly when the column cannot be toggled.
     *
     * @return list<array{type: string, name: string, isToggled: bool, isToggledHiddenByDefault: bool|null}>
     */
    private function getTestedTableColumnState(): array
    {
        $host = $this->getTestedTableHost();

        if (!property_exists($host, 'tableColumns') || !is_array($host->tableColumns)) {
            throw new LogicException(sprintf('The [%s] component keeps no column-manager state.', $host::class));
        }

        $state = [];

        foreach ($host->tableColumns as $column) {
            $isToggledHiddenByDefault = is_array($column) ? ($column['isToggledHiddenByDefault'] ?? null) : null;

            if (
                !is_array($column)
                || !is_string($column['type'] ?? null)
                || !is_string($column['name'] ?? null)
                || !is_bool($column['isToggled'] ?? null)
                || !is_bool($isToggledHiddenByDefault) && $isToggledHiddenByDefault !== null
            ) {
                throw new LogicException(sprintf('The [%s] component keeps a column-manager item that is not a column state.', $host::class));
            }

            $state[] = [
                'type' => $column['type'],
                'name' => $column['name'],
                'isToggled' => $column['isToggled'],
                'isToggledHiddenByDefault' => $isToggledHiddenByDefault,
            ];
        }

        return $state;
    }
}
