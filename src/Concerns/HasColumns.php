<?php

declare(strict_types=1);

namespace Sylament\Tables\Concerns;

use Closure;
use Sylament\Schemas\Exceptions\ValidationException;
use Sylament\Tables\Columns\Column;
use Sylament\Tables\Columns\Contracts\Editable;
use Sylament\Tables\Exceptions\RecordWriteException;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;

trait HasColumns
{
    /**
     * What a refused write left behind, keyed by column name and record key. A
     * plain property rather than a `LiveProp`: the refusal lives for the request
     * that produced it, the re-render paints it under the control, and the next
     * request starts clean.
     *
     * @var array<string, array<string, array{message: string, input: mixed}>>
     */
    private array $tableColumnStateErrors = [];

    /**
     * Runs the closure a column's `action()` holds, for the record the client
     * names. Every client value is untrusted: the key resolves through the
     * query lane with the table's filters re-applied, so a filtered-out record
     * is simply absent, and the column resolves by name off the table. A
     * missing record, an unknown column, a column the developer hid or an
     * action that is not a closure returns without effect.
     */
    #[LiveAction]
    public function callTableColumnAction(#[LiveArg] string $name, #[LiveArg] string $recordKey): void
    {
        $record = $this->getTableRecord($recordKey);

        if ($record === null) {
            return;
        }

        $column = $this->getTable()->getColumn($name);

        if (!$column instanceof Column) {
            return;
        }

        if ($column->isHidden()) {
            return;
        }

        $action = $column->getAction();

        if (!$action instanceof Closure) {
            return;
        }

        $column->record($record)->recordKey($recordKey)->evaluate($action);
    }

    /**
     * Writes one editable cell's new state back to its record. Every client
     * value is untrusted: the column resolves by name off the table, must be
     * `Editable` and must not be one the developer hid, the record key resolves
     * through the query lane with the table's filters re-applied, and the input
     * is validated before any write — with the record bound first, so a
     * `disabled()` closure judges the row it was handed. Each refusal returns
     * without effect; a validation refusal, and a value the record's own type
     * turns the write down, are filed against this cell for the re-render to
     * paint.
     *
     * The write is deliberately not authorization-checked — see
     * {@see \Sylament\Tables\Columns\Concerns\CanUpdateState::updateState()}.
     */
    #[LiveAction]
    public function updateTableColumnState(#[LiveArg] string $column, #[LiveArg] string $record, #[LiveArg] mixed $input): void
    {
        $tableColumn = $this->getTable()->getColumn($column);

        if (!$tableColumn instanceof Column || !$tableColumn instanceof Editable) {
            return;
        }

        if ($tableColumn->isHidden()) {
            return;
        }

        $tableRecord = $this->getTableRecord($record);

        if ($tableRecord === null) {
            return;
        }

        $tableColumn->record($tableRecord)->recordKey($record);

        if ($tableColumn->isDisabled()) {
            return;
        }

        try {
            $tableColumn->validate($input);
        } catch (ValidationException $validationException) {
            $messages = $validationException->getErrors()[$column] ?? [];

            $this->tableColumnStateErrors[$column][$record] = [
                'message' => $messages[0] ?? 'The value is invalid.',
                'input' => $input,
            ];

            return;
        }

        try {
            $tableColumn->updateState($input);
        } catch (RecordWriteException $recordWriteException) {
            $this->tableColumnStateErrors[$column][$record] = [
                'message' => $recordWriteException->getMessage(),
                'input' => $input,
            ];

            return;
        }

        $tableColumn->clearCachedState();
        $this->flushCachedTableRecords();
    }

    /**
     * @return array{message: string, input: mixed}|null
     */
    public function getTableColumnStateError(string $column, string $recordKey): ?array
    {
        return $this->tableColumnStateErrors[$column][$recordKey] ?? null;
    }
}
