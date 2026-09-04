<?php

declare(strict_types=1);

namespace Sylament\Tables\Tests\Fixtures\Twig\Components;

use Doctrine\ORM\EntityManagerInterface;
use Sylament\Tables\Columns\CheckboxColumn;
use Sylament\Tables\Columns\SelectColumn;
use Sylament\Tables\Columns\TextColumn;
use Sylament\Tables\Columns\TextInputColumn;
use Sylament\Tables\Columns\ToggleColumn;
use Sylament\Tables\Concerns\HasTable;
use Sylament\Tables\Contracts\InteractsWithTable;
use Sylament\Tables\Table;
use Sylament\Tables\Tests\Fixtures\Doctrine\Entity\Song;
use Sylament\Tables\Tests\Fixtures\Records\Format;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * The write-back seam under a real database: every editable column over one
 * query-lane table, so a live `updateTableColumnState()` round trip can be read
 * back off the container's entity manager rather than off the host instance the
 * next request re-hydrates.
 *
 * The toggle and the checkbox sit on different fields because `columns()` keys
 * by name; the first two selects are the nullable and the non-nullable arm of
 * the presence gate. The query keeps the loudest songs out, so a record key the
 * database holds but the table does not reach has something to point at. The
 * format select is the enum arm: its option map is a backed enum class, so the
 * posted backing string only reaches the enum-typed property through the
 * column's state cast.
 *
 * Two columns carry hooks that exist to make an invariant observable. The title
 * column's `beforeStateUpdated()` reads the old state — what a hook comparing
 * old against new does — which caches it under the record key, so the write's
 * own `clearCachedState()` is what the re-render's new value depends on. The
 * position select is the override arm: integer option keys against the string
 * the browser posts, an `updateStateUsing()` closure doing the write itself
 * instead of the accessor, and an `afterStateUpdated()` hook adding 100 on top,
 * so the wire can see the hook run on the arm that returns early.
 *
 * Two more columns write the title through a closure of their own, under names
 * no record property carries, and neither renders. One is hidden: it is what the
 * host's hidden-column gate refuses, and without the gate the write lands on a
 * real field and the test reads it back off the database. The other is
 * `toggleable()` and hidden by default — the column the *user* hid, whose write
 * the gate must still let through, because that is their own preference rather
 * than the developer's visibility.
 */
#[AsLiveComponent(name: 'EditableSongsTable', template: '@TablesFixtures/components/EditableSongsTable.html.twig')]
final class EditableSongsTable implements InteractsWithTable
{
    use DefaultActionTrait;
    use HasTable;

    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public function table(Table $table): Table
    {
        return $table
            ->query($this->entityManager->createQueryBuilder()
                ->select('song')
                ->from(Song::class, 'song')
                ->where('song.plays < 1000'))
            ->columns([
                TextColumn::make('plays'),
                ToggleColumn::make('isFeatured')
                    ->disabled(fn (Song $record): bool => $record->getId() === 3)
                    ->onColor('success')
                    ->onIcon('heroicons:check-circle')
                    ->offIcon('heroicons:x-circle'),
                CheckboxColumn::make('isExplicit'),
                TextInputColumn::make('title')
                    ->constraint(new Length(min: 3))
                    ->beforeStateUpdated(fn (TextInputColumn $component): mixed => $component->getState())
                    ->placeholder('Untitled')
                    ->prefix('#', isInline: true),
                SelectColumn::make('mood')
                    ->options(['calm' => 'Calm', 'wild' => 'Wild'])
                    ->disableOptionWhen(fn (mixed $value): bool => $value === 'wild'),
                SelectColumn::make('tempo')->options(['slow' => 'Slow', 'fast' => 'Fast']),
                SelectColumn::make('format')->options(Format::class),
                TextInputColumn::make('secretTitle')
                    ->hidden()
                    ->state(fn (Song $record): string => $record->getTitle())
                    ->updateStateUsing(function (Song $record, mixed $state): void {
                        $record->setTitle(is_string($state) ? $state : '');
                        $this->entityManager->flush();
                    }),
                TextInputColumn::make('toggledTitle')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->state(fn (Song $record): string => $record->getTitle())
                    ->updateStateUsing(function (Song $record, mixed $state): void {
                        $record->setTitle(is_string($state) ? $state : '');
                        $this->entityManager->flush();
                    }),
                SelectColumn::make('position')
                    ->options([1 => 'First', 2 => 'Second'])
                    ->updateStateUsing(function (Song $record, mixed $state): void {
                        $record->setPosition($state === null ? null : (int) $state);
                    })
                    ->afterStateUpdated(function (Song $record): void {
                        $record->setPosition(($record->getPosition() ?? 0) + 100);
                        $this->entityManager->flush();
                    }),
            ]);
    }
}
