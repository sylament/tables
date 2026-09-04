<?php

declare(strict_types=1);

namespace Sylament\Tables\Tests\Fixtures\Twig\Components;

use Doctrine\ORM\EntityManager;
use Sylament\Tables\Columns\TextColumn;
use Sylament\Tables\Concerns\HasTable;
use Sylament\Tables\Contracts\InteractsWithTable;
use Sylament\Tables\Table;
use Sylament\Tables\Tests\Fixtures\Doctrine\Entity\Song;
use Sylament\Tables\Tests\Fixtures\Doctrine\EntityManagerFactory;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * The deferred column-manager host, over the same per-request in-memory SQLite
 * rig the filter fixtures use. Its four columns cover every arm the state
 * machine has: one that cannot be toggled at all, one toggleable and on, one
 * toggleable and hidden by default, and one hidden outright — which the manager
 * never lists and no state can bring back.
 */
#[AsLiveComponent(name: 'ColumnManagerTable', template: '@TablesFixtures/components/ColumnManagerTable.html.twig')]
class ColumnManagerTable implements InteractsWithTable
{
    use DefaultActionTrait;
    use HasTable;

    private ?EntityManager $entityManager = null;

    public function table(Table $table): Table
    {
        return $table
            ->query($this->songs()->createQueryBuilder()->select('song')->from(Song::class, 'song'))
            ->columns([
                TextColumn::make('title'),
                TextColumn::make('plays')->toggleable(),
                TextColumn::make('position')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('isFeatured')->hidden(),
            ])
            ->paginated([2, 5]);
    }

    private function songs(): EntityManager
    {
        if ($this->entityManager instanceof EntityManager) {
            return $this->entityManager;
        }

        $entityManager = EntityManagerFactory::create();

        EntityManagerFactory::seed(
            $entityManager,
            new Song(1, 'Airbag', 100, position: 1),
            new Song(2, 'Paranoid Android', 200, position: 2),
            new Song(3, 'Exit Music', 200, position: 3),
            new Song(4, 'Let Down', 100, position: 4),
        );

        return $this->entityManager = $entityManager;
    }
}
