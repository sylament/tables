<?php

declare(strict_types=1);

namespace Sylament\Tables\Tests\Fixtures\Twig\Components;

use Closure;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Sylament\Actions\AttachAction;
use Sylament\Actions\Concerns\HasActions;
use Sylament\Actions\Contracts\InteractsWithActions;
use Sylament\Actions\DetachAction;
use Sylament\Actions\DetachBulkAction;
use Sylament\Schemas\Concerns\HasSchemas;
use Sylament\Schemas\Contracts\InteractsWithSchemas;
use Sylament\Schemas\State\Relationship;
use Sylament\Tables\Columns\TextColumn;
use Sylament\Tables\Concerns\HasTable;
use Sylament\Tables\Contracts\InteractsWithTable;
use Sylament\Tables\Table;
use Sylament\Tables\Tests\Fixtures\Doctrine\Entity\Genre;
use Sylament\Tables\Tests\Fixtures\Doctrine\Entity\Song;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * The attach family's host: one song's genres, carrying the three many-to-many
 * actions. It is a schema host as well as an action host because the attach
 * modal carries a record select, and it declares no schemas of its own — the
 * mounted action's is the only one.
 */
#[AsLiveComponent(name: 'SongGenresActionsTable', template: '@TablesFixtures/components/SongGenresActionsTable.html.twig')]
class SongGenresActionsTable implements InteractsWithActions, InteractsWithSchemas, InteractsWithTable
{
    use DefaultActionTrait;
    use HasActions;
    use HasSchemas;
    use HasTable;

    #[LiveProp]
    public int $songId = 1;

    #[LiveProp]
    public bool $fetchesRecords = true;

    #[LiveProp]
    public bool $multiple = false;

    #[LiveProp]
    public bool $preloads = false;

    #[LiveProp]
    public bool $titlesByClosure = false;

    /**
     * Puts a `recordTitle()` closure on the **action** rather than the table —
     * the arm the action's own custom title has to win, and which no table-level
     * title can stand in for.
     */
    #[LiveProp]
    public bool $actionTitlesByClosure = false;

    /**
     * Puts a `recordTitleAttribute()` on the **action**, which overrides a table
     * that titles its records by closure.
     */
    #[LiveProp]
    public ?string $actionTitleAttribute = null;

    #[LiveProp]
    public ?bool $forcesCaseInsensitiveSearch = null;

    /**
     * @var list<string>|null
     */
    #[LiveProp]
    public ?array $searchColumns = null;

    /**
     * Which arm of `recordSelectOptionsQuery()` to install: `'name'` takes the
     * builder by parameter name and the search beside it, `'type'` takes the
     * builder by type hint alone, and `'order'` orders the builder itself.
     */
    #[LiveProp]
    public ?string $optionsQueryArm = null;

    /**
     * Every `$search` the options-query closure was handed, in call order — the
     * only way to see the second documented argument arrive.
     *
     * @var list<string|null>
     */
    public array $optionsQuerySearches = [];

    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public function table(Table $table): Table
    {
        $table
            ->relationship(function (): ?Relationship {
                $song = $this->entityManager->find(Song::class, $this->songId);

                return $song instanceof Song ? new Relationship($song, 'genres') : null;
            })
            ->recordTitleAttribute('name')
            ->columns([TextColumn::make('name')])
            ->headerActions([
                AttachAction::make()
                    ->multiple($this->multiple)
                    ->preloadRecordSelect($this->preloads)
                    ->forceSearchCaseInsensitive($this->forcesCaseInsensitiveSearch)
                    ->recordSelectSearchColumns($this->searchColumns)
                    ->recordSelectOptionsQuery($this->optionsQueryCallback())
                    ->recordTitle($this->actionTitlesByClosure ? static fn (Genre $record): string => "Genre: {$record->getName()}" : null)
                    ->recordTitleAttribute($this->actionTitleAttribute),
            ])
            ->recordActions([DetachAction::make()])
            ->toolbarActions([DetachBulkAction::make()->fetchSelectedRecords($this->fetchesRecords)])
            ->paginated([10]);

        if ($this->titlesByClosure) {
            $table->recordTitle(fn (Genre $record): string => mb_strtoupper($record->getName()));
        }

        return $table;
    }

    /**
     * @return array<string>
     */
    public function schemas(): array
    {
        return [];
    }

    /**
     * `'name'` leaves the builder untyped, so only the by-name injection can
     * fill it; `'type'` names it something else, so only the by-type one can;
     * `'order'` sorts by name descending, the opposite of the title order the
     * action would impose.
     */
    private function optionsQueryCallback(): ?Closure
    {
        return match ($this->optionsQueryArm) {
            'name' => function (mixed $query, ?string $search): QueryBuilder {
                $this->optionsQuerySearches[] = $search;
                assert($query instanceof QueryBuilder);

                return $query->andWhere("record.name <> 'Rock'");
            },
            'type' => static fn (QueryBuilder $builder): QueryBuilder => $builder->andWhere("record.name <> 'Pop'"),
            'order' => static fn (QueryBuilder $builder): QueryBuilder => $builder->orderBy('record.name', 'DESC'),
            default => null,
        };
    }
}
