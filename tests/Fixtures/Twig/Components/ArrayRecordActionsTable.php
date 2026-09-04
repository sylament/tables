<?php

declare(strict_types=1);

namespace Sylament\Tables\Tests\Fixtures\Twig\Components;

use Sylament\Tables\Columns\TextColumn;
use Sylament\Tables\Concerns\HasTable;
use Sylament\Tables\Contracts\InteractsWithTable;
use Sylament\Tables\Table;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * The `records()` lane's other record shape: array rows rather than objects,
 * under a column whose `action()` is a method name. The string arm wraps that
 * name into a closure the Invoker injects the record into by name, so the arrays
 * this table carries are what its parameter has to admit.
 */
#[AsLiveComponent(name: 'ArrayRecordActionsTable', template: '@TablesFixtures/components/ArrayRecordActionsTable.html.twig')]
final class ArrayRecordActionsTable implements InteractsWithTable
{
    use DefaultActionTrait;
    use HasTable;

    /**
     * @var list<string>
     */
    #[LiveProp]
    public array $calls = [];

    public function table(Table $table): Table
    {
        return $table
            ->records(fn (): array => [
                '1' => ['title' => 'Airbag'],
                '2' => ['title' => 'Let Down'],
            ])
            ->columns([TextColumn::make('title')->action('appendTitleCall')]);
    }

    /**
     * @param object|array<string, mixed>|null $record
     */
    public function appendTitleCall(object|array|null $record = null): string
    {
        $this->calls[] = 'array:' . (is_array($record) ? (string) ($record['title'] ?? '') : 'none');

        return 'called';
    }
}
