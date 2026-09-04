<?php

declare(strict_types=1);

namespace Sylament\Tables\Tests\Fixtures\Twig\Components;

use Doctrine\ORM\EntityManager;
use Sylament\Tables\Columns\Summarizers\Average;
use Sylament\Tables\Columns\Summarizers\Count;
use Sylament\Tables\Columns\Summarizers\Range;
use Sylament\Tables\Columns\Summarizers\Sum;
use Sylament\Tables\Columns\Summarizers\Values;
use Sylament\Tables\Columns\TextColumn;
use Sylament\Tables\Concerns\HasTable;
use Sylament\Tables\Contracts\InteractsWithTable;
use Sylament\Tables\Table;
use Sylament\Tables\Tests\Fixtures\Doctrine\Entity\Song;
use Sylament\Tables\Tests\Fixtures\Doctrine\EntityManagerFactory;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * The summarized host, over the same per-request in-memory SQLite rig the column
 * manager fixture uses, paginated two of five so the page row and the all-table
 * row can never read the same figure. Its four columns cover the tier: two
 * batched summarizers on one column, a count on another, a range whose ends
 * differ, and a list whose values repeat.
 */
#[AsLiveComponent(name: 'SummarySongsTable', template: '@TablesFixtures/components/SummarySongsTable.html.twig')]
class SummarySongsTable implements InteractsWithTable
{
    use DefaultActionTrait;
    use HasTable;

    private ?EntityManager $entityManager = null;

    public function table(Table $table): Table
    {
        return $table
            ->query($this->songs()->createQueryBuilder()->select('song')->from(Song::class, 'song')->orderBy('song.id', 'ASC'))
            ->columns([
                TextColumn::make('title')->summarize(Range::make()->minimalTextualDifference()),
                TextColumn::make('plays')->summarize([Sum::make(), Average::make()]),
                TextColumn::make('position')->summarize(Count::make()->label('Rows')),
                TextColumn::make('mood')->summarize(Values::make()),
            ])
            ->paginated([2]);
    }

    private function songs(): EntityManager
    {
        if ($this->entityManager instanceof EntityManager) {
            return $this->entityManager;
        }

        $entityManager = EntityManagerFactory::create();

        EntityManagerFactory::seed(
            $entityManager,
            new Song(id: 1, title: 'Airbag', plays: 10, mood: 'calm', position: 1),
            new Song(id: 2, title: 'Airport', plays: 20, mood: 'calm', position: 2),
            new Song(id: 3, title: 'Jóga', plays: 30, mood: 'bright', position: 3),
            new Song(id: 4, title: 'Hyperballad', plays: 40, mood: 'bright', position: 4),
            new Song(id: 5, title: 'Bachelorette', plays: 50, mood: 'dark', position: 5),
        );

        return $this->entityManager = $entityManager;
    }
}
