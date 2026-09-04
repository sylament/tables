<?php

declare(strict_types=1);

use Sylament\Actions\Action;
use Sylament\Tables\Grouping\Group;
use Sylament\Tables\Table;
use Sylament\Tables\Tests\Fixtures\Concerns\TableHost;

/*
 * The definition half of grouping: which groups a table offers, which one it
 * defaults to, the chrome flags and the toolbar trigger. Which group is *in
 * force* is the host's, and stays in `Unit/Concerns/CanGroupRecordsTest.php`.
 */

function groupingTable(?Closure $configure = null): Table
{
    return new TableHost($configure)->getTable();
}

it('keys the registered groups by id', function (): void {
    $table = groupingTable(fn (Table $table): Table => $table->groups(['status', Group::make('artist.name')]));

    expect(array_keys($table->getGroups()))->toBe(['status', 'artist.name']);
});

it('promotes a string to a Group and binds the table to it', function (): void {
    $table = groupingTable(fn (Table $table): Table => $table->groups(['status']));

    expect($table->getGroup('status'))->toBeInstanceOf(Group::class)
        ->and($table->getGroup('status')?->getTable())->toBe($table);
});

it('evaluates a groups() closure', function (): void {
    $table = groupingTable(fn (Table $table): Table => $table->groups(
        fn (Table $table): array => [$table->getRecordLabel() === 'record' ? 'status' : 'other'],
    ));

    expect(array_keys($table->getGroups()))->toBe(['status']);
});

it('memoises the registered groups', function (): void {
    $built = 0;

    $table = groupingTable(function (Table $table) use (&$built): Table {
        return $table->groups(function () use (&$built): array {
            $built++;

            return [];
        });
    });

    $table->getGroups();
    $table->getGroups();

    expect($built)->toBe(1)
        ->and($table->getGroups())->toBe([]);
});

it('answers null for an unregistered id', function (): void {
    expect(groupingTable(fn (Table $table): Table => $table->groups(['status']))->getGroup('nope'))->toBeNull();
});

it('builds an unregistered default group on the fly and binds the table', function (): void {
    $table = groupingTable(fn (Table $table): Table => $table->defaultGroup('status'));
    $default = $table->getDefaultGroup();

    expect($default)->toBeInstanceOf(Group::class)
        ->and($default?->getId())->toBe('status')
        ->and($default?->getTable())->toBe($table)
        ->and($table->getGroups())->toBe([]);
});

it('takes a Group instance as the default and binds the table', function (): void {
    $group = Group::make('artist.name');
    $table = groupingTable(fn (Table $table): Table => $table->defaultGroup($group));

    expect($table->getDefaultGroup())->toBe($group)
        ->and($group->getTable())->toBe($table);
});

it('takes the registered instance when the default names a registered id', function (): void {
    $table = groupingTable(fn (Table $table): Table => $table->groups(['status'])->defaultGroup('status'));

    expect($table->getDefaultGroup())->toBe($table->getGroup('status'));
});

it('has no default group until one is named', function (): void {
    expect(groupingTable()->getDefaultGroup())->toBeNull();
});

it('reports the default group selectable only when it is also registered', function (): void {
    $unregistered = groupingTable(fn (Table $table): Table => $table->defaultGroup('status'));
    $registered = groupingTable(fn (Table $table): Table => $table->groups(['status'])->defaultGroup('status'));

    expect(groupingTable()->isDefaultGroupSelectable())->toBeFalse()
        ->and($unregistered->isDefaultGroupSelectable())->toBeFalse()
        ->and($registered->isDefaultGroupSelectable())->toBeTrue();
});

it('defaults every chrome flag off', function (): void {
    $table = groupingTable();

    expect($table->isGroupsOnly())->toBeFalse()
        ->and($table->areGroupingSettingsInDropdownOnDesktop())->toBeFalse()
        ->and($table->areGroupingSettingsHidden())->toBeFalse()
        ->and($table->isGroupingDirectionSettingHidden())->toBeFalse()
        ->and($table->areGroupsCollapsedByDefault())->toBeFalse();
});

it('evaluates each chrome flag as a closure', function (string $setter, string $getter): void {
    $on = groupingTable(fn (Table $table): Table => $table->{$setter}(fn (): bool => true));
    $off = groupingTable(fn (Table $table): Table => $table->{$setter}(fn (): bool => false));

    expect($on->{$getter}())->toBeTrue()
        ->and($off->{$getter}())->toBeFalse();
})->with([
    'groupsOnly' => ['groupsOnly', 'isGroupsOnly'],
    'groupingSettingsInDropdownOnDesktop' => ['groupingSettingsInDropdownOnDesktop', 'areGroupingSettingsInDropdownOnDesktop'],
    'groupingSettingsHidden' => ['groupingSettingsHidden', 'areGroupingSettingsHidden'],
    'groupingDirectionSettingHidden' => ['groupingDirectionSettingHidden', 'isGroupingDirectionSettingHidden'],
    'collapsedGroupsByDefault' => ['collapsedGroupsByDefault', 'areGroupsCollapsedByDefault'],
]);

it('builds a grouping trigger action carrying the group icon and the gray colour', function (): void {
    $table = groupingTable(fn (Table $table): Table => $table->groups(['status']));
    $action = $table->getGroupRecordsTriggerAction();

    expect($action->getName())->toBe('groupRecords')
        ->and($action->getLabel())->toBe('Group')
        ->and($action->getIcon())->toBe('heroicons:rectangle-stack-20-solid')
        ->and($action->getColor())->toBe('gray')
        ->and($action->isLiveComponentClickHandlerEnabled())->toBeFalse()
        ->and($action->getTable())->toBe($table);
});

it('takes a groupRecordsTriggerAction modifier closure over the built action', function (): void {
    $seen = 0;

    $modified = groupingTable(function (Table $table) use (&$seen): Table {
        return $table->groupRecordsTriggerAction(function (Action $action) use (&$seen): Action {
            $seen++;

            return $action->label('Group by');
        });
    });

    $untouched = groupingTable(fn (Table $table): Table => $table->groupRecordsTriggerAction(fn (): null => null));

    expect($modified->getGroupRecordsTriggerAction()->getLabel())->toBe('Group by')
        ->and($seen)->toBe(1)
        ->and($untouched->getGroupRecordsTriggerAction()->getLabel())->toBe('Group');
});

it('delegates the grouping in force to the host', function (): void {
    $host = new TableHost(fn (Table $table): Table => $table->groups(['status']));
    $host->tableGrouping = 'status';

    expect($host->getTable()->getGrouping())->toBe($host->getTable()->getGroup('status'));
});
