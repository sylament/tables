<?php

declare(strict_types=1);

namespace Sylament\Tables\Tests\Integration\Table\Concerns;

use Sylament\Support\Icons\IconManager;
use Sylament\Tables\Columns\TextColumn;
use Sylament\Tables\Table;
use Sylament\Tables\Tests\Fixtures\Concerns\TableHost;
use Sylament\Tables\Twig\TablesIconAlias;

/*
 * The one thing the manager's table tier needs a container for: the trigger's
 * glyph is a closure resolving `ACTIONS_COLUMN_MANAGER` off the injected icon
 * manager, so an application override reaches it and the shipped fallback
 * answers when nothing is registered.
 */

function columnManagerTriggerIcon(): string
{
    $icon = new TableHost(fn (Table $table): Table => $table->columns([TextColumn::make('title')->toggleable()]))
        ->getTable()
        ->getColumnManagerTriggerAction()
        ->getIcon();

    return (string) $icon;
}

it('falls back to the shipped glyph when nothing overrides the manager alias', function (): void {
    expect(columnManagerTriggerIcon())->toBe('heroicons:view-columns-20-solid');
});

it('lets a registered alias replace the trigger glyph', function (): void {
    $this::getContainer()->get(IconManager::class)->register([
        TablesIconAlias::ACTIONS_COLUMN_MANAGER => 'heroicons:x-mark-20-solid',
    ]);

    expect(columnManagerTriggerIcon())->toBe('heroicons:x-mark-20-solid');
});
