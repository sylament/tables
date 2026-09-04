<?php

declare(strict_types=1);

namespace Sylament\Tables\Concerns;

use LogicException;
use Sylament\Tables\Columns\Column;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\Attribute\PostHydrate;
use Symfony\UX\TwigComponent\Attribute\PostMount;
use Twig\Markup;

trait HasColumnManager
{
    public const string TABLE_COLUMN_MANAGER_COLUMN_TYPE = 'column';

    /**
     * The stored shape's forward slot: never produced here, honoured on read so
     * a state a later version writes still loads.
     */
    public const string TABLE_COLUMN_MANAGER_GROUP_TYPE = 'group';

    /** @var list<array{type: string, name: string, isToggled: bool, isToggledHiddenByDefault: bool|null}> */
    #[LiveProp]
    public array $tableColumns = [];

    /** @var list<array{type: string, name: string, isToggled: bool, isToggledHiddenByDefault: bool|null}> */
    #[LiveProp(writable: true)]
    public array $tableDeferredColumns = [];

    /** @var list<array{type: string, name: string, isToggled: bool, isToggledHiddenByDefault: bool|null}>|null */
    private ?array $cachedDefaultTableColumnState = null;

    /** @var array<string, bool>|null */
    private ?array $tableColumnsToggleStateByName = null;

    /**
     * Seeds the applied state from the session, or from the definition where
     * the session carries nothing usable, and hands the draft the same value.
     *
     * Priority 4 puts it below the persisted-state read at 10 and the filters
     * mount at 20, and above the filters hook at 0; the slot at 5 is the sort
     * heal's.
     */
    #[PostMount(priority: 4)]
    public function mountTableColumnManager(): void
    {
        if (!$this->hasTableColumnState()) {
            return;
        }

        $this->setTableColumns(self::normalizeTableColumnState($this->readTableSessionState('columns')) ?? $this->getDefaultTableColumnState());
        $this->syncTableColumnState();
        $this->tableDeferredColumns = $this->tableColumns;
        $this->persistTableColumns();
    }

    /**
     * Re-derives both bags against the definition on every round trip: the
     * applied state arrives as a plain prop and the draft is client-writable, so
     * neither is trusted and a column the definition no longer declares is gone
     * before anything reads it.
     */
    #[PostHydrate(priority: -4)]
    public function afterTableColumnManagerHydrated(): void
    {
        if (!$this->hasTableColumnState()) {
            return;
        }

        $this->setTableColumns(self::normalizeTableColumnState($this->tableColumns) ?? $this->getDefaultTableColumnState());
        $this->syncTableColumnState();
        $this->tableDeferredColumns = $this->syncTableColumnStateItems(self::normalizeTableColumnState($this->tableDeferredColumns) ?? $this->tableColumns);
    }

    /**
     * @param array<mixed>|null $state an explicit state, else the draft the manager's controller wrote
     */
    #[LiveAction]
    public function applyTableColumnManager(#[LiveArg] ?array $state = null): void
    {
        $this->setTableColumns(self::normalizeTableColumnState($state ?? $this->tableDeferredColumns) ?? $this->getDefaultTableColumnState());
        $this->syncTableColumnState();
        $this->tableDeferredColumns = $this->tableColumns;
        $this->flushCachedTableRecords();
        $this->persistTableColumns();
    }

    #[LiveAction]
    public function resetTableColumnManager(): void
    {
        $this->setTableColumns($this->getDefaultTableColumnState());
        $this->syncTableColumnState();
        $this->tableDeferredColumns = $this->tableColumns;
        $this->flushCachedTableRecords();
        $this->persistTableColumns();
    }

    /**
     * An absent name reads as hidden: both sync directions leave every declared
     * column in the state before anything reads it, and a column never asks
     * about itself unless it is toggleable.
     */
    public function isTableColumnToggledHidden(string $name): bool
    {
        return !($this->getTableColumnsToggleStateByName()[$name] ?? false);
    }

    /**
     * The manager's rows in the draft's order: every declared, unhidden column
     * with its label, whether the user may toggle it, and the current choice.
     *
     * @return list<array{name: string, label: string|Markup, isToggleable: bool, isToggled: bool}>
     */
    public function getTableColumnManagerItems(): array
    {
        $table = $this->getTable();
        $items = [];

        foreach (self::normalizeTableColumnState($this->tableDeferredColumns) ?? [] as $item) {
            if ($item['type'] !== self::TABLE_COLUMN_MANAGER_COLUMN_TYPE) {
                continue;
            }

            $column = $table->getColumn($item['name']);

            if (!$column instanceof Column || $column->isHidden()) {
                continue;
            }

            $items[] = [
                'name' => $column->getName(),
                'label' => $column->getLabel(),
                'isToggleable' => $column->isToggleable(),
                'isToggled' => $item['isToggled'],
            ];
        }

        return $items;
    }

    /**
     * @return list<array{type: string, name: string, isToggled: bool, isToggledHiddenByDefault: bool|null}>
     */
    public function getDefaultTableColumnState(): array
    {
        return $this->cachedDefaultTableColumnState ??= array_map(
            $this->mapTableColumnToArray(...),
            array_values($this->getTable()->getColumns()),
        );
    }

    /**
     * @return array{type: string, name: string, isToggled: bool, isToggledHiddenByDefault: bool|null}
     */
    protected function mapTableColumnToArray(Column $column): array
    {
        if ($this->getTable()->hasReorderableColumns() && mb_trim((string) $column->getLabel()) === '') {
            throw new LogicException(sprintf('The table column [%s] has a blank label. All columns must have labels when they are reorderable.', $column->getName()));
        }

        $isToggleable = $column->isToggleable();

        return [
            'type' => self::TABLE_COLUMN_MANAGER_COLUMN_TYPE,
            'name' => $column->getName(),
            'isToggled' => !$isToggleable || !$column->isToggledHiddenByDefault(),
            'isToggledHiddenByDefault' => $isToggleable ? $column->isToggledHiddenByDefault() : null,
        ];
    }

    /**
     * Replaces the applied column set, dropping everything memoised from the
     * old one: this trait's per-name toggle map and the table's visible set.
     *
     * @param list<array{type: string, name: string, isToggled: bool, isToggledHiddenByDefault: bool|null}> $tableColumns
     */
    protected function setTableColumns(array $tableColumns): void
    {
        $this->tableColumns = $tableColumns;
        $this->tableColumnsToggleStateByName = null;

        $this->getTable()->flushCachedVisibleColumns();
    }

    /**
     * The definition owns identity and toggleability; the stored state owns the
     * toggle and, while columns are reorderable, the order. The table's own
     * column order follows the state whenever it may be reordered, so a reset
     * or an order restored by hand renders in the declared order again.
     */
    protected function syncTableColumnState(): void
    {
        $this->setTableColumns($this->syncTableColumnStateItems($this->tableColumns));

        if (!$this->getTable()->hasReorderableColumns()) {
            return;
        }

        $this->updateTableColumns();
    }

    /**
     * @param list<array{type: string, name: string, isToggled: bool, isToggledHiddenByDefault: bool|null}> $state
     *
     * @return list<array{type: string, name: string, isToggled: bool, isToggledHiddenByDefault: bool|null}>
     */
    protected function syncTableColumnStateItems(array $state): array
    {
        $default = $this->getDefaultTableColumnState();

        if ($this->getTable()->hasReorderableColumns() && $this->isReorderedTableColumnState($state)) {
            $synced = [];

            foreach ($state as $item) {
                $match = self::findMatchingTableColumnStateItem($item, $default);

                if ($match !== null) {
                    $synced[] = self::syncTableColumnStateItemAttributes($item, $match);
                }
            }

            return [...$synced, ...self::getNewDefaultColumnStateItems($state, $default)];
        }

        return array_map(static function (array $item) use ($state): array {
            $stored = self::findMatchingTableColumnStateItem($item, $state);

            return $stored === null ? $item : self::syncTableColumnStateItemAttributes($stored, $item);
        }, $default);
    }

    /**
     * Reads the default state first, so the declared order is memoised before
     * the table is re-columned in the stored one and a later reset can still
     * restore it.
     */
    protected function updateTableColumns(): void
    {
        $this->getDefaultTableColumnState();

        $table = $this->getTable();
        $columns = [];

        foreach ($this->tableColumns as $item) {
            $column = $table->getColumn($item['name']);

            if ($column instanceof Column) {
                $columns[] = $column;
            }
        }

        $table->columns($columns);
    }

    protected function persistTableColumns(): void
    {
        if (!$this->getTable()->persistsColumnsInSession()) {
            return;
        }

        $this->persistTableStateToSession('columns', $this->tableColumns);
    }

    /**
     * The state exists for any column the manager could toggle or move, whether
     * or not the chrome that edits it is shown — `hasColumnManager()` gates only
     * the trigger, so `columnManager(false)` over toggleable columns keeps their
     * declared defaults.
     */
    private function hasTableColumnState(): bool
    {
        $table = $this->getTable();

        return $table->hasReorderableColumns() || $table->hasToggleableColumns();
    }

    /**
     * @return array<string, bool>
     */
    private function getTableColumnsToggleStateByName(): array
    {
        if ($this->tableColumnsToggleStateByName !== null) {
            return $this->tableColumnsToggleStateByName;
        }

        $map = [];

        foreach ($this->tableColumns as $item) {
            if ($item['type'] === self::TABLE_COLUMN_MANAGER_COLUMN_TYPE) {
                $map[$item['name']] = $item['isToggled'];
            }
        }

        return $this->tableColumnsToggleStateByName = $map;
    }

    /**
     * Filament's own test, parameterised over a state rather than reading the
     * applied one: the stored order compared against the declared order over the
     * columns both know.
     *
     * @param list<array{type: string, name: string, isToggled: bool, isToggledHiddenByDefault: bool|null}> $state
     */
    private function isReorderedTableColumnState(array $state): bool
    {
        $stored = self::flattenTableColumnStateItems($state);
        $declared = array_values(array_filter(
            self::flattenTableColumnStateItems($this->getDefaultTableColumnState()),
            static fn (string $key): bool => in_array($key, $stored, true),
        ));

        return $stored !== $declared;
    }

    /**
     * @param list<array{type: string, name: string, isToggled: bool, isToggledHiddenByDefault: bool|null}> $items
     *
     * @return list<string>
     */
    private static function flattenTableColumnStateItems(array $items): array
    {
        return array_map(static fn (array $item): string => $item['type'] . ':' . $item['name'], $items);
    }

    /**
     * The stored item with the definition's attributes over it: toggled on
     * whenever the column cannot be toggled, and re-seeded from the definition's
     * default when the stored sentinel says the column was not toggleable when
     * it was stored.
     *
     * @param array{type: string, name: string, isToggled: bool, isToggledHiddenByDefault: bool|null} $item
     * @param array{type: string, name: string, isToggled: bool, isToggledHiddenByDefault: bool|null} $default
     *
     * @return array{type: string, name: string, isToggled: bool, isToggledHiddenByDefault: bool|null}
     */
    private static function syncTableColumnStateItemAttributes(array $item, array $default): array
    {
        if ($default['isToggledHiddenByDefault'] === null) {
            $item['isToggled'] = true;
        } elseif ($item['isToggledHiddenByDefault'] === null) {
            $item['isToggled'] = !$default['isToggledHiddenByDefault'];
        }

        $item['isToggledHiddenByDefault'] = $default['isToggledHiddenByDefault'];

        return $item;
    }

    /**
     * @param array{type: string, name: string, isToggled: bool, isToggledHiddenByDefault: bool|null} $item
     * @param list<array{type: string, name: string, isToggled: bool, isToggledHiddenByDefault: bool|null}> $items
     *
     * @return array{type: string, name: string, isToggled: bool, isToggledHiddenByDefault: bool|null}|null
     */
    private static function findMatchingTableColumnStateItem(array $item, array $items): ?array
    {
        foreach ($items as $candidate) {
            if ($candidate['type'] === $item['type'] && $candidate['name'] === $item['name']) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @param list<array{type: string, name: string, isToggled: bool, isToggledHiddenByDefault: bool|null}> $state
     * @param list<array{type: string, name: string, isToggled: bool, isToggledHiddenByDefault: bool|null}> $default
     *
     * @return list<array{type: string, name: string, isToggled: bool, isToggledHiddenByDefault: bool|null}>
     */
    private static function getNewDefaultColumnStateItems(array $state, array $default): array
    {
        $existing = self::flattenTableColumnStateItems($state);

        return array_values(array_filter($default, static fn (array $item): bool => !in_array($item['type'] . ':' . $item['name'], $existing, true)));
    }

    /**
     * A state arriving from outside — the session, or the writable draft — is as
     * untrusted as any other client value: a non-list, or an item without a
     * string type and name, is refused whole and the caller falls back to the
     * default state; a toggle that is not a bool reads as on.
     *
     * @return list<array{type: string, name: string, isToggled: bool, isToggledHiddenByDefault: bool|null}>|null
     */
    private static function normalizeTableColumnState(mixed $state): ?array
    {
        if (!is_array($state) || !array_is_list($state)) {
            return null;
        }

        $normalized = [];

        foreach ($state as $item) {
            if (!is_array($item) || !is_string($item['type'] ?? null) || !is_string($item['name'] ?? null)) {
                return null;
            }

            $isToggled = $item['isToggled'] ?? null;
            $hiddenByDefault = $item['isToggledHiddenByDefault'] ?? null;

            $normalized[] = [
                'type' => $item['type'],
                'name' => $item['name'],
                'isToggled' => is_bool($isToggled) ? $isToggled : true,
                'isToggledHiddenByDefault' => is_bool($hiddenByDefault) ? $hiddenByDefault : null,
            ];
        }

        return $normalized;
    }
}
