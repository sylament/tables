<?php

declare(strict_types=1);

namespace Sylament\Tables\Tests\Fixtures\Twig\Components;

use Doctrine\ORM\EntityManager;
use Sylament\Actions\BulkAction;
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
use Sylament\Tables\Tests\Fixtures\Doctrine\RecordingMiddleware;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * The grouping host: a query-lane table over a per-request in-memory SQLite
 * database, offering two groups and carrying one bulk action, paginated two to
 * a page so each group of three outlives the page it opens on — which is what
 * makes a group checkbox's keys provably the whole filtered set. The
 * selectability flag is a `LiveProp` so a test can rebuild the same table with
 * the callback set across a round trip, and the page-only flag another so the
 * narrower selection arm can be reached the same way. Every statement the
 * connection runs is recorded, so a test can price a read in queries.
 */
#[AsLiveComponent(name: 'GroupedSongsTable', template: '@TablesFixtures/components/GroupedSongsTable.html.twig')]
final class GroupedSongsTable implements InteractsWithActions, InteractsWithSchemas, InteractsWithTable
{
    use DefaultActionTrait;
    use HasActions;
    use HasSchemas;
    use HasTable;

    #[LiveProp]
    public bool $checksSelectable = false;

    #[LiveProp]
    public bool $selectsCurrentPageOnly = false;

    private ?EntityManager $entityManager = null;

    private ?RecordingMiddleware $recorder = null;

    /**
     * How many statements the connection has run since the component was built.
     */
    public function recordedStatementCount(): int
    {
        return count($this->recorder?->statements() ?? []);
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
        $table
            ->query($this->songs()->createQueryBuilder()->select('song')->from(Song::class, 'song'))
            ->columns([TextColumn::make('title'), TextColumn::make('plays')])
            ->groups(['artist.name', 'plays'])
            ->toolbarActions([BulkAction::make('archive')->action(fn (): null => null)])
            ->paginated([2]);

        if ($this->checksSelectable) {
            $table->checkIfRecordIsSelectableUsing(fn (Song $record): bool => $record->getId() !== 2);
        }

        if ($this->selectsCurrentPageOnly) {
            $table->selectCurrentPageOnly();
        }

        return $table;
    }

    private function songs(): EntityManager
    {
        if ($this->entityManager instanceof EntityManager) {
            return $this->entityManager;
        }

        $this->recorder = new RecordingMiddleware();

        $entityManager = EntityManagerFactory::create($this->recorder);
        $radiohead = new Artist(1, 'Radiohead');
        $zeroSeven = new Artist(2, 'Zero 7');

        EntityManagerFactory::seed(
            $entityManager,
            $radiohead,
            $zeroSeven,
            new Song(1, 'Airbag', 100, $radiohead),
            new Song(2, 'Let Down', 100, $radiohead),
            new Song(3, 'Creep', 200, $radiohead),
            new Song(4, 'Destiny', 200, $zeroSeven),
            new Song(5, 'In the Waiting Line', 300, $zeroSeven),
            new Song(6, 'Somersault', 300, $zeroSeven),
        );

        return $this->entityManager = $entityManager;
    }
}
