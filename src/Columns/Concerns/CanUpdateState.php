<?php

declare(strict_types=1);

namespace Sylament\Tables\Columns\Concerns;

use BackedEnum;
use Closure;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use LogicException;
use Stringable;
use Sylament\Schemas\State\Casts\Contracts\StateCast;
use Sylament\Tables\Exceptions\RecordWriteException;
use Symfony\Contracts\Service\Attribute\Required;

use function Sylament\Support\blank;

trait CanUpdateState
{
    protected ?Closure $updateStateUsing = null;

    protected ?Closure $beforeStateUpdated = null;

    protected ?Closure $afterStateUpdated = null;

    protected ?ManagerRegistry $managerRegistry = null;

    #[Required]
    public function setManagerRegistry(?ManagerRegistry $managerRegistry = null): void
    {
        $this->managerRegistry = $managerRegistry;
    }

    /**
     * The conversions standing between the vocabulary the browser posts and the
     * one the record holds, outermost first: `get()` runs over the posted value
     * on the way in, `set()` in reverse order on the way back out to the control.
     *
     * @return array<StateCast>
     */
    public function getStateCasts(): array
    {
        return [];
    }

    public function updateStateUsing(?Closure $callback): static
    {
        $this->updateStateUsing = $callback;

        return $this;
    }

    public function beforeStateUpdated(?Closure $callback): static
    {
        $this->beforeStateUpdated = $callback;

        return $this;
    }

    public function afterStateUpdated(?Closure $callback): static
    {
        $this->afterStateUpdated = $callback;

        return $this;
    }

    public function updateState(mixed $state): mixed
    {
        if (blank($state)) {
            $state = null;
        }

        foreach ($this->getStateCasts() as $stateCast) {
            $state = $stateCast->get($state);
        }

        $this->callBeforeStateUpdated($state);

        if ($this->updateStateUsing instanceof Closure) {
            try {
                return $this->evaluate($this->updateStateUsing, ['state' => $state]);
            } finally {
                $this->callAfterStateUpdated($state);
            }
        }

        $record = $this->getRecord();

        if (!is_object($record)) {
            throw new LogicException(sprintf(
                'The editable column [%s] has no record to write to on the records() lane; give it updateStateUsing().',
                $this->getName(),
            ));
        }

        // Security: the write is not authorization-checked, and the toolkit has
        // no authorization layer at all; only `disabled()` is checked, by the
        // host before it calls here. A column whose write must be restricted
        // takes a `disabled()` closure, or the record is edited through a page
        // or modal action the application guards itself.
        $this->writeStateOntoRecord($record, $state);

        $manager = $this->getEntityManagerForRecord($record);
        $manager->persist($record);
        $manager->flush();

        $this->callAfterStateUpdated($state);

        return $state;
    }

    public function callBeforeStateUpdated(mixed $state): mixed
    {
        return $this->evaluate($this->beforeStateUpdated, ['state' => $state]);
    }

    public function callAfterStateUpdated(mixed $state): mixed
    {
        return $this->evaluate($this->afterStateUpdated, ['state' => $state]);
    }

    /**
     * The refusal the host filed against this cell on this request, or null.
     *
     * @return array{message: string, input: mixed}|null
     */
    public function getUpdateStateError(): ?array
    {
        $recordKey = $this->getRecordKey();

        if ($recordKey === null) {
            return null;
        }

        return $this->getTable()->getLiveComponent()->getTableColumnStateError($this->getName(), $recordKey);
    }

    /**
     * The display state the cell root publishes for its controller to repaint
     * the control from: the refused input while the host holds one for this
     * cell, else the record's own state — `'1'`/`'0'` for a boolean control and
     * the string form (`''` for null) otherwise. Only the record's state is
     * unwound through the casts; a refused input is echoed as it was posted,
     * because it is already the vocabulary the control speaks.
     */
    public function getClientState(): string
    {
        $error = $this->getUpdateStateError();

        if ($error === null) {
            $state = $this->getState();

            foreach (array_reverse($this->getStateCasts()) as $stateCast) {
                $state = $stateCast->set($state);
            }
        } else {
            $state = $error['input'];
        }

        if ($this->hasBooleanClientState()) {
            return $state ? '1' : '0';
        }

        return match (true) {
            is_bool($state) => $state ? '1' : '0',
            $state instanceof BackedEnum => (string) $state->value,
            is_scalar($state), $state instanceof Stringable => (string) $state,
            default => '',
        };
    }

    /**
     * Whether the cell's control speaks `checked` rather than `value`, which the
     * two boolean columns answer true.
     */
    protected function hasBooleanClientState(): bool
    {
        return false;
    }

    /**
     * The manager that owns the record's class — the table query's, else the
     * registry's.
     */
    protected function getEntityManagerForRecord(object $record): EntityManagerInterface
    {
        $manager = isset($this->table) ? $this->getTable()->getQueryEntityManager() : null;

        if ($manager instanceof EntityManagerInterface) {
            return $manager;
        }

        $registry = $this->managerRegistry
            ?? throw new LogicException(sprintf('The editable column [%s] persists a record, which needs the container-built instance — register it as a service so the manager registry is injected.', $this->getName()));

        $manager = $registry->getManagerForClass($record::class)
            ?? throw new LogicException(sprintf('No Doctrine manager handles %s.', $record::class));

        if (!$manager instanceof EntityManagerInterface) {
            throw new LogicException(sprintf('The editable column [%s] needs an ORM entity manager for %s, got %s.', $this->getName(), $record::class, get_debug_type($manager)));
        }

        return $manager;
    }

    /**
     * The accessor refuses a path whose leaf is an object collection, and a value
     * the record's declared type cannot hold, each in its own vocabulary;
     * re-thrown here naming the column, which is what the reader of a table has
     * in hand, and as the write seam's own class, which is what lets the host
     * file the refusal against the cell rather than let it out.
     */
    private function writeStateOntoRecord(object $record, mixed $state): void
    {
        try {
            $this->getPropertyAccessor()->setOnRecord($record, $this->getName(), $state);
        } catch (LogicException $logicException) {
            throw new RecordWriteException(sprintf(
                'The editable column [%s] cannot write to its record: %s',
                $this->getName(),
                $logicException->getMessage(),
            ), $logicException->getCode(), previous: $logicException);
        }
    }
}
