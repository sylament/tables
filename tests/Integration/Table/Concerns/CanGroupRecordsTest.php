<?php

declare(strict_types=1);

namespace Sylament\Tables\Tests\Integration\Table\Concerns;

use Sylament\Support\Icons\IconManager;
use Sylament\Tables\Columns\TextColumn;
use Sylament\Tables\Table;
use Sylament\Tables\Tests\Fixtures\Concerns\TableHost;
use Sylament\Tables\Twig\TablesIconAlias;

/*
 * The one thing the grouping's table tier needs a container for: the trigger's
 * glyph resolves `ACTIONS_GROUP` off the injected icon manager, so an
 * application override reaches it and the shipped fallback answers when nothing
 * is registered.
 */

function groupRecordsTriggerIcon(): string
{
    $icon = new TableHost(fn (Table $table): Table => $table->columns([TextColumn::make('title')])->groups(['title']))
        ->getTable()
        ->getGroupRecordsTriggerAction()
        ->getIcon();

    return (string) $icon;
}

it('falls back to the shipped glyph when nothing overrides the grouping alias', function (): void {
    expect(groupRecordsTriggerIcon())->toBe('heroicons:rectangle-stack-20-solid');
});

it('lets a registered alias replace the grouping trigger glyph', function (): void {
    $this::getContainer()->get(IconManager::class)->register([
        TablesIconAlias::ACTIONS_GROUP => 'heroicons:x-mark-20-solid',
    ]);

    expect(groupRecordsTriggerIcon())->toBe('heroicons:x-mark-20-solid');
});
