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
use Sylament\Tables\Filters\SelectFilter;
use Sylament\Tables\Filters\TernaryFilter;
use Sylament\Tables\Table;
use Sylament\Tables\Tests\Fixtures\Doctrine\Entity\Song;
use Sylament\Tables\Tests\Fixtures\Doctrine\EntityManagerFactory;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * The table the query-string and session tests browse: sortable, searchable,
 * paginated two-to-a-page, and filtered by a select whose default is a real
 * value — so a seeded filter has a default to beat — beside a ternary, whose
 * `'true'` / `'false'` query-string values only become booleans if the seeded
 * bag is normalized.
 *
 * Concrete subclasses decide whether any of it reaches the URL.
 */
abstract class BrowsingStateTable implements InteractsWithActions, InteractsWithSchemas, InteractsWithTable
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
                TextColumn::make('title')->sortable()->searchable(),
                TextColumn::make('plays')->sortable(),
            ])
            ->filters([
                SelectFilter::make('plays')
                    ->options(['100' => 'Hundred', '200' => 'Two hundred'])
                    ->default('100'),
                TernaryFilter::make('featured')
                    ->attribute('isFeatured'),
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

        EntityManagerFactory::seed(
            $entityManager,
            new Song(1, 'Airbag', 100, isFeatured: true),
            new Song(2, 'Paranoid Android', 200, isFeatured: false),
            new Song(3, 'Exit Music', 100, isFeatured: true),
            new Song(4, 'Let Down', 100, isFeatured: false),
            new Song(5, 'Karma Police', 200, isFeatured: true),
            new Song(6, 'No Surprises', 100, isFeatured: false),
        );

        return $this->entityManager = $entityManager;
    }
}
