<?php

declare(strict_types=1);

namespace Sylament\Tables\Tests\Fixtures\Twig\Components;

use Doctrine\ORM\EntityManager;
use Sylament\Actions\Concerns\HasActions;
use Sylament\Actions\Contracts\InteractsWithActions;
use Sylament\Schemas\Concerns\HasSchemas;
use Sylament\Schemas\Contracts\InteractsWithSchemas;
use Sylament\Tables\Columns\TextColumn;
use Sylament\Tables\Concerns\HasTable;
use Sylament\Tables\Contracts\InteractsWithTable;
use Sylament\Tables\Table;
use Sylament\Tables\Tests\Fixtures\Doctrine\Entity\Artist;
use Sylament\Tables\Tests\Fixtures\Doctrine\Entity\Song;
use Sylament\Tables\Tests\Fixtures\Doctrine\EntityManagerFactory;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * The per-column search host: every arm of the seeded bag on one table — a plain
 * individually searchable column, a dotted one that nests, one that is
 * individually searchable and not globally, a column named after a reserved
 * JavaScript array property, a merely global column that must never be seeded,
 * and a hidden individual one whose term the query pass must ignore.
 */
#[AsLiveComponent(name: 'ColumnSearchTable', template: '@TablesFixtures/components/ColumnSearchTable.html.twig')]
final class ColumnSearchTable implements InteractsWithActions, InteractsWithSchemas, InteractsWithTable
{
    use DefaultActionTrait;
    use HasActions;
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
            ->columns([
                TextColumn::make('title')->searchable(isIndividual: true),
                TextColumn::make('artist.name')->searchable(isIndividual: true, isGlobal: false),
                TextColumn::make('plays')->searchable(),
                TextColumn::make('entries')->searchable(isIndividual: true),
                TextColumn::make('mood')->searchable(isIndividual: true)->hidden(),
            ])
            ->searchable()
            ->paginated([2, 5])
            ->defaultPaginationPageOption(2);
    }

    private function songs(): EntityManager
    {
        if ($this->entityManager instanceof EntityManager) {
            return $this->entityManager;
        }

        $entityManager = EntityManagerFactory::create();
        $radiohead = new Artist(1, 'Radiohead');
        $portishead = new Artist(2, 'Portishead');

        EntityManagerFactory::seed(
            $entityManager,
            $radiohead,
            $portishead,
            new Song(1, 'Airbag', 100, $radiohead, mood: 'tense', entries: 11),
            new Song(2, 'Paranoid Android', 200, $radiohead, mood: 'tense', entries: 22),
            new Song(3, 'Glory Box', 300, $portishead, mood: 'calm', entries: 33),
            new Song(4, 'Let Down', 400, $radiohead, mood: 'calm', entries: 44),
        );

        return $this->entityManager = $entityManager;
    }
}
