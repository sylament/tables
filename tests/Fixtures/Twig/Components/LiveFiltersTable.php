<?php

declare(strict_types=1);

namespace Sylament\Tables\Tests\Fixtures\Twig\Components;

use Doctrine\ORM\EntityManager;
use Sylament\Schemas\Concerns\HasSchemas;
use Sylament\Schemas\Contracts\InteractsWithSchemas;
use Sylament\Tables\Columns\TextColumn;
use Sylament\Tables\Concerns\HasTable;
use Sylament\Tables\Contracts\InteractsWithTable;
use Sylament\Tables\Filters\SelectFilter;
use Sylament\Tables\Table;
use Sylament\Tables\Tests\Fixtures\Doctrine\Entity\Song;
use Sylament\Tables\Tests\Fixtures\Doctrine\EntityManagerFactory;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * The live-filters host: `deferFilters(false)`, so the filters schema binds
 * straight at `tableFilters` and a prop write re-filters without an apply step.
 * Its one filter carries a `default()`, which is what `resetTableFiltersForm()`
 * restores.
 */
#[AsLiveComponent(name: 'LiveFiltersTable', template: '@TablesFixtures/components/LiveFiltersTable.html.twig')]
class LiveFiltersTable implements InteractsWithSchemas, InteractsWithTable
{
    use DefaultActionTrait;
    use HasSchemas;
    use HasTable;

    private ?EntityManager $entityManager = null;

    /**
     * @return array<string>
     */
    public function schemas(): array
    {
        return [];
    }

    public function table(Table $table): Table
    {
        return $table
            ->query($this->songs()->createQueryBuilder()->select('song')->from(Song::class, 'song'))
            ->columns([TextColumn::make('title')->searchable()])
            ->filters([
                SelectFilter::make('plays')
                    ->options(['100' => 'Hundred', '200' => 'Two hundred'])
                    ->default('200'),
            ])
            ->deferFilters(false)
            ->searchable()
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
            new Song(1, 'Airbag', 100),
            new Song(2, 'Paranoid Android', 200),
            new Song(3, 'Exit Music', 200),
            new Song(4, 'Let Down', 100),
        );

        return $this->entityManager = $entityManager;
    }
}
