<?php

declare(strict_types=1);

namespace Sylament\Tables\Tests\Fixtures\Twig\Components;

use Doctrine\ORM\EntityManagerInterface;
use Sylament\Actions\Concerns\HasActions;
use Sylament\Actions\Contracts\InteractsWithActions;
use Sylament\Actions\CreateAction;
use Sylament\Forms\Components\TextInput;
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
 * The creating relation-scoped host, in both mapping shapes `$shape` selects
 * between: a song's genres (many-to-many, owning side) and an artist's songs
 * (one-to-many, the target's `artist` field carrying the membership). The
 * modal supplies the identifier because the rig's entities carry assigned ones.
 *
 * `createElsewhere` is the same create against a different owner — its own
 * `relationship()` closure, which wins over the table's.
 */
#[AsLiveComponent(name: 'CreateIntoRelationshipTable', template: '@TablesFixtures/components/CreateIntoRelationshipTable.html.twig')]
class CreateIntoRelationshipTable implements InteractsWithActions, InteractsWithSchemas, InteractsWithTable
{
    use DefaultActionTrait;
    use HasActions;
    use HasSchemas;
    use HasTable;

    #[LiveProp]
    public string $shape = 'genres';

    #[LiveProp]
    public int $songId = 1;

    #[LiveProp]
    public int $artistId = 1;

    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    /**
     * @return array<string>
     */
    public function schemas(): array
    {
        return [];
    }

    public function table(Table $table): Table
    {
        return $this->shape === 'genres' ? $this->genresTable($table) : $this->songsTable($table);
    }

    private function genresTable(Table $table): Table
    {
        return $table
            ->relationship(fn (): ?Relationship => $this->relationshipTo(Song::class, $this->songId, 'genres'))
            ->columns([TextColumn::make('name')])
            ->headerActions([
                CreateAction::make()->schema($this->genreSchema()),
                CreateAction::make('createElsewhere')
                    ->schema($this->genreSchema())
                    ->relationship(fn (): ?Relationship => $this->relationshipTo(Song::class, 2, 'genres')),
            ]);
    }

    private function songsTable(Table $table): Table
    {
        return $table
            ->relationship(fn (): ?Relationship => $this->relationshipTo(Artist::class, $this->artistId, 'songs'))
            ->columns([
                TextColumn::make('title'),
                TextColumn::make('plays'),
            ])
            ->headerActions([
                CreateAction::make()->schema([
                    TextInput::make('id')->required(),
                    TextInput::make('title')->required(),
                    TextInput::make('plays')->required(),
                ]),
            ]);
    }

    /**
     * @return array<TextInput>
     */
    private function genreSchema(): array
    {
        return [
            TextInput::make('id')->required(),
            TextInput::make('name')->required(),
        ];
    }

    /**
     * @param class-string $ownerClass
     */
    private function relationshipTo(string $ownerClass, int $ownerId, string $name): ?Relationship
    {
        $owner = $this->entityManager->find($ownerClass, $ownerId);

        return is_object($owner) ? new Relationship($owner, $name) : null;
    }
}
