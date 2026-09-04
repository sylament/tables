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
 * The live, reorderable column-manager host: no apply step, so a state reaches
 * the applied bag on the round trip that carries it, and the stored order is
 * honoured because the columns may be moved. It is also the fixture that opted
 * out of remembering its columns, which is the only way to prove the write is
 * gated rather than unconditional.
 */
#[AsLiveComponent(name: 'LiveColumnManagerTable', template: '@TablesFixtures/components/LiveColumnManagerTable.html.twig')]
class LiveColumnManagerTable implements InteractsWithTable
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
            ->deferColumnManager(false)
            ->reorderableColumns()
            ->persistColumnsInSession(false)
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
