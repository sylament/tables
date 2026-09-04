<?php

declare(strict_types=1);

namespace Sylament\Tables\Table\Concerns;

use BackedEnum;
use Closure;
use Sylament\Actions\Action;
use Sylament\Support\Icons\IconManager;
use Sylament\Tables\Grouping\Group;
use Sylament\Tables\Twig\TablesIconAlias;
use Symfony\Contracts\Service\Attribute\Required;
use Twig\Markup;

trait CanGroupRecords
{
    protected string|Group|Closure|null $defaultGroup = null;

    /**
     * @var array<string|Group>|Closure
     */
    protected array|Closure $groups = [];

    protected bool|Closure $isGroupsOnly = false;

    protected bool|Closure $areGroupingSettingsInDropdownOnDesktop = false;

    protected bool|Closure $areGroupingSettingsHidden = false;

    protected bool|Closure $isGroupingDirectionSettingHidden = false;

    protected bool|Closure $areGroupsCollapsedByDefault = false;

    protected Action|Closure|null $modifyGroupRecordsTriggerActionUsing = null;

    /**
     * @var array<string, Group>
     */
    private array $cachedGroups = [];

    private bool $hasCachedGroups = false;

    private ?IconManager $groupingIconManager = null;

    #[Required]
    public function setGroupingIconManager(IconManager $iconManager): void
    {
        $this->groupingIconManager = $iconManager;
    }

    /**
     * @param array<string|Group>|Closure $groups
     */
    public function groups(array|Closure $groups): static
    {
        $this->groups = $groups;

        return $this;
    }

    public function defaultGroup(string|Group|Closure|null $group): static
    {
        $this->defaultGroup = $group;

        return $this;
    }

    public function groupsOnly(bool|Closure $condition = true): static
    {
        $this->isGroupsOnly = $condition;

        return $this;
    }

    public function groupingSettingsInDropdownOnDesktop(bool|Closure $condition = true): static
    {
        $this->areGroupingSettingsInDropdownOnDesktop = $condition;

        return $this;
    }

    public function groupingSettingsHidden(bool|Closure $condition = true): static
    {
        $this->areGroupingSettingsHidden = $condition;

        return $this;
    }

    public function groupingDirectionSettingHidden(bool|Closure $condition = true): static
    {
        $this->isGroupingDirectionSettingHidden = $condition;

        return $this;
    }

    public function collapsedGroupsByDefault(bool|Closure $condition = true): static
    {
        $this->areGroupsCollapsedByDefault = $condition;

        return $this;
    }

    public function groupRecordsTriggerAction(?Closure $callback): static
    {
        $this->modifyGroupRecordsTriggerActionUsing = $callback;

        return $this;
    }

    /**
     * The groups the user may choose between, keyed by id and each bound to this
     * table. Memoised behind a flag rather than behind the array itself, since a
     * table declaring none memoises `[]` legitimately.
     *
     * @return array<string, Group>
     */
    public function getGroups(): array
    {
        if ($this->hasCachedGroups) {
            return $this->cachedGroups;
        }

        $groups = [];

        foreach ($this->evaluate($this->groups) as $group) {
            if (!$group instanceof Group) {
                $group = Group::make($group);
            }

            $groups[$group->getId()] = $group->table($this);
        }

        $this->hasCachedGroups = true;

        return $this->cachedGroups = $groups;
    }

    public function getGroup(string $id): ?Group
    {
        return $this->getGroups()[$id] ?? null;
    }

    /**
     * The group a table falls back to — a declared instance, the registered
     * group of that id, or one built on the spot for an id `groups()` never
     * offered, which is how a table groups by something the user cannot change.
     */
    public function getDefaultGroup(): ?Group
    {
        $defaultGroup = $this->evaluate($this->defaultGroup);

        if ($defaultGroup === null) {
            return null;
        }

        if ($defaultGroup instanceof Group) {
            return $defaultGroup->table($this);
        }

        return $this->getGroup($defaultGroup) ?? Group::make($defaultGroup)->table($this);
    }

    /**
     * Whether the default group is one of the offered ones — the test that
     * decides whether the default is seeded into the host's property at mount
     * (so the selector can show it, and offer no grouping beside it) or resolved
     * fresh on every read and never shown at all.
     */
    public function isDefaultGroupSelectable(): bool
    {
        $defaultGroup = $this->getDefaultGroup();

        return $defaultGroup instanceof Group && $this->getGroup($defaultGroup->getId()) instanceof Group;
    }

    public function getGrouping(): ?Group
    {
        return $this->getLiveComponent()->getTableGrouping();
    }

    public function isGroupsOnly(): bool
    {
        return (bool) $this->evaluate($this->isGroupsOnly);
    }

    public function areGroupingSettingsInDropdownOnDesktop(): bool
    {
        return (bool) $this->evaluate($this->areGroupingSettingsInDropdownOnDesktop);
    }

    public function areGroupingSettingsHidden(): bool
    {
        return (bool) $this->evaluate($this->areGroupingSettingsHidden);
    }

    public function isGroupingDirectionSettingHidden(): bool
    {
        return (bool) $this->evaluate($this->isGroupingDirectionSettingHidden);
    }

    public function areGroupsCollapsedByDefault(): bool
    {
        return (bool) $this->evaluate($this->areGroupsCollapsedByDefault);
    }

    public function getGroupRecordsTriggerAction(): Action
    {
        $action = Action::make('groupRecords')
            ->label('Group')
            ->iconButton()
            ->icon($this->resolveGroupingIcon(TablesIconAlias::ACTIONS_GROUP, 'heroicons:rectangle-stack-20-solid'))
            ->color('gray')
            ->liveComponentClickHandlerEnabled(false)
            ->table($this);

        return $this->evaluate($this->modifyGroupRecordsTriggerActionUsing, ['action' => $action]) ?? $action;
    }

    /**
     * The manager arrives through a setter of this trait's own name: `Table`
     * flattens `CanReorderRecords`' `setIconManager()` into the same class, and a
     * second setter under that name is a trait collision rather than a second
     * injection. A table built outside the container — a unit fixture — falls
     * back to a bare manager, which resolves no alias and answers the fallback
     * glyph rather than failing to build the action.
     */
    private function resolveGroupingIcon(string $alias, string $fallback): string|BackedEnum|Markup
    {
        return ($this->groupingIconManager ??= new IconManager())->resolve($alias) ?? $fallback;
    }
}
