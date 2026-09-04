<?php

declare(strict_types=1);

namespace Sylament\Tables\Tests\Fixtures\Twig\Components;

use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use Sylament\Tables\Columns\TextInputColumn;
use Sylament\Tables\Concerns\HasTable;
use Sylament\Tables\Contracts\InteractsWithTable;
use Sylament\Tables\Table;
use Sylament\Tables\Tests\Fixtures\Doctrine\Entity\Song;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * The type-refusal rig: four text inputs over record properties whose declared
 * types differ, none of them carrying a constraint of its own, so what stands
 * between a posted value and the record is only what the column derives.
 *
 * `mood` and `plays` are the two seeded arms — a string and an integer property,
 * where the derived `Type` refuses the wrong shape before any write. `artist` is
 * the arm nothing can seed: an object-typed property, where the accessor is what
 * refuses, and the refusal has to reach the error bag rather than the client.
 * `tempo` carries an `updateStateUsing()` that throws, which pins that the write
 * seam's catch never swallows user code.
 *
 * It is a fixture of its own rather than more columns on
 * {@see EditableSongsTable} because a table keys its columns by name, and every
 * property these need is already spoken for there.
 */
#[AsLiveComponent(name: 'TypeRefusalSongsTable', template: '@TablesFixtures/components/TypeRefusalSongsTable.html.twig')]
final class TypeRefusalSongsTable implements InteractsWithTable
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
                ->from(Song::class, 'song'))
            ->columns([
                TextInputColumn::make('mood'),
                TextInputColumn::make('plays'),
                TextInputColumn::make('artist'),
                TextInputColumn::make('tempo')->updateStateUsing(function (): never {
                    throw new LogicException('The override arm refused this write.');
                }),
            ]);
    }
}
