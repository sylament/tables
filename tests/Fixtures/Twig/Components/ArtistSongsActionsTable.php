<?php

declare(strict_types=1);

namespace Sylament\Tables\Tests\Fixtures\Twig\Components;

use Closure;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Sylament\Actions\AssociateAction;
use Sylament\Actions\Concerns\HasActions;
use Sylament\Actions\Contracts\InteractsWithActions;
use Sylament\Actions\DissociateAction;
use Sylament\Actions\DissociateBulkAction;
use Sylament\Forms\Components\Select;
use Sylament\Schemas\Concerns\HasSchemas;
use Sylament\Schemas\Contracts\InteractsWithSchemas;
use Sylament\Schemas\State\Relationship;
use Sylament\Tables\Columns\TextColumn;
use Sylament\Tables\Concerns\HasTable;
use Sylament\Tables\Contracts\InteractsWithTable;
use Sylament\Tables\Table;
use Sylament\Tables\Tests\Fixtures\Doctrine\Entity\Artist;
use Sylament\Tables\Tests\Fixtures\Doctrine\Entity\Song;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * The one-to-many host for the associate family: one artist's songs, carrying
 * the associate/dissociate trio. It is a schema host too, because the associate
 * modal's record select is a schema — and `$association` swings the table onto
 * `Artist::$albums`, the orphan-removal mapping the dissociate refusals fire on.
 */
#[AsLiveComponent(name: 'ArtistSongsActionsTable', template: '@TablesFixtures/components/ArtistSongsActionsTable.html.twig')]
class ArtistSongsActionsTable implements InteractsWithActions, InteractsWithSchemas, InteractsWithTable
{
    use DefaultActionTrait;
    use HasActions;
    use HasSchemas;
    use HasTable;

    #[LiveProp]
    public int $artistId = 1;

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
    public string $association = 'songs';

    /**
     * Makes the record select label every submitted key, so a key the candidate
     * query excludes clears the select's own membership check and reaches the
     * action's re-resolution guard behind it.
     */
    #[LiveProp]
    public bool $offersEverySong = false;

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

    /**
     * The host declares no schema of its own — the only schema here is the one
     * the associate modal mounts.
     *
     * @return array<string>
     */
    public function schemas(): array
    {
        return [];
    }

    public function table(Table $table): Table
    {
        $table
            ->relationship(function (): ?Relationship {
                $artist = $this->entityManager->find(Artist::class, $this->artistId);

                return $artist instanceof Artist ? new Relationship($artist, $this->association) : null;
            })
            ->recordTitleAttribute('title')
            ->columns([TextColumn::make('title')])
            ->headerActions([
                AssociateAction::make()
                    ->multiple($this->multiple)
                    ->preloadRecordSelect($this->preloads)
                    ->forceSearchCaseInsensitive($this->forcesCaseInsensitiveSearch)
                    ->recordSelectSearchColumns($this->searchColumns)
                    ->recordSelectOptionsQuery($this->optionsQueryCallback())
                    ->recordSelect($this->offersEverySong ? static fn (Select $select): Select => $select->getOptionLabelUsing(static fn (): string => 'Karma Police') : null)
                    ->recordTitle($this->actionTitlesByClosure ? static fn (Song $record): string => "Song: {$record->getTitle()}" : null)
                    ->recordTitleAttribute($this->actionTitleAttribute),
            ])
            ->recordActions([DissociateAction::make()])
            ->toolbarActions([DissociateBulkAction::make()->fetchSelectedRecords($this->fetchesRecords)])
            ->paginated([10]);

        if ($this->titlesByClosure) {
            $table->recordTitle(fn (Song $record): string => mb_strtoupper($record->getTitle()));
        }

        return $table;
    }

    /**
     * `'name'` leaves the builder untyped, so only the by-name injection can
     * fill it; `'type'` names it something else, so only the by-type one can;
     * `'order'` sorts by plays, which puts the candidates in the opposite order
     * to their titles.
     */
    private function optionsQueryCallback(): ?Closure
    {
        return match ($this->optionsQueryArm) {
            'name' => function (mixed $query, ?string $search): QueryBuilder {
                $this->optionsQuerySearches[] = $search;
                assert($query instanceof QueryBuilder);

                return $query->andWhere("record.title <> 'Glory Box'");
            },
            'type' => static fn (QueryBuilder $builder): QueryBuilder => $builder->andWhere("record.title <> 'Exit Music'"),
            'order' => static fn (QueryBuilder $builder): QueryBuilder => $builder->orderBy('record.plays', 'ASC'),
            default => null,
        };
    }
}
