<?php

declare(strict_types=1);

namespace Sylament\Tables\Tests\Fixtures\Twig\Components;

use Doctrine\ORM\EntityManager;
use Doctrine\ORM\QueryBuilder;
use Sylament\Actions\Action;
use Sylament\Actions\Concerns\HasActions;
use Sylament\Actions\Contracts\InteractsWithActions;
use Sylament\Forms\Components\TextInput;
use Sylament\Schemas\Concerns\HasSchemas;
use Sylament\Schemas\Contracts\InteractsWithSchemas;
use Sylament\Tables\Columns\TextColumn;
use Sylament\Tables\Concerns\HasTable;
use Sylament\Tables\Contracts\InteractsWithTable;
use Sylament\Tables\Filters\Filter;
use Sylament\Tables\Filters\Indicator;
use Sylament\Tables\Filters\SelectFilter;
use Sylament\Tables\Table;
use Sylament\Tables\Tests\Fixtures\Doctrine\Entity\Song;
use Sylament\Tables\Tests\Fixtures\Doctrine\EntityManagerFactory;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

use function Sylament\Support\filled;

/**
 * The deferred-filters host: a query-lane table over a per-request in-memory
 * SQLite database, carrying a select filter, a checkbox filter with its own
 * query closure, and a two-field custom-schema filter whose indicators are keyed
 * so a chip removal narrows to one field.
 */
#[AsLiveComponent(name: 'FiltersTable', template: '@TablesFixtures/components/FiltersTable.html.twig')]
class FiltersTable implements InteractsWithActions, InteractsWithSchemas, InteractsWithTable
{
    use DefaultActionTrait;
    use HasActions;
    use HasSchemas;
    use HasTable;

    #[LiveProp]
    public bool $excludesPlaysFilterWhenResolvingRecord = false;

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
                    ->excludeWhenResolvingRecord($this->excludesPlaysFilterWhenResolvingRecord),
                Filter::make('popular')
                    ->query(function (QueryBuilder $query): void {
                        $query->andWhere('song.plays >= 150');
                    }),
                Filter::make('playsRange')
                    ->schema([
                        TextInput::make('from'),
                        TextInput::make('until'),
                    ])
                    ->indicateUsing(function (array $data): array {
                        $indicators = [];

                        if (filled($data['from'] ?? null)) {
                            $indicators['from'] = Indicator::make('From: ' . $data['from']);
                        }

                        if (filled($data['until'] ?? null)) {
                            $indicators['until'] = Indicator::make('Until: ' . $data['until']);
                        }

                        return $indicators;
                    })
                    ->query(function (QueryBuilder $query, array $data): void {
                        if (filled($data['from'] ?? null)) {
                            $query->andWhere('song.plays >= :playsFrom')->setParameter('playsFrom', (int) $data['from']);
                        }

                        if (filled($data['until'] ?? null)) {
                            $query->andWhere('song.plays <= :playsUntil')->setParameter('playsUntil', (int) $data['until']);
                        }
                    }),
            ])
            ->recordActions([
                Action::make('inspect')
                    ->requiresConfirmation()
                    ->modalHeading(fn (Song $record): string => sprintf('Inspect "%s"', $record->getTitle())),
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
            new Song(1, 'Airbag', 100),
            new Song(2, 'Paranoid Android', 200),
            new Song(3, 'Exit Music', 200),
            new Song(4, 'Let Down', 100),
        );

        return $this->entityManager = $entityManager;
    }
}
