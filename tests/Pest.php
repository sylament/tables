<?php

declare(strict_types=1);

namespace Sylament\Tables\Tests;

use Closure;
use Doctrine\ORM\EntityManagerInterface;
use Sylament\Schemas\State\AssociationReader;
use Sylament\Support\Components\ViewComponent;
use Sylament\Support\Configuration\ComponentManager;
use Sylament\Support\DependencyInjection\ComponentResolver;
use Sylament\Tables\Filters\BaseFilter;
use Sylament\Tables\Table;
use Sylament\Tables\Tests\Fixtures\Concerns\FilterStateHost;
use Sylament\Tables\Tests\Fixtures\Concerns\TableHost;
use Sylament\Tables\Tests\Fixtures\Doctrine\Entity\Genre;
use Sylament\Tables\Tests\Fixtures\Doctrine\Entity\Song;
use Sylament\Tables\Tests\Fixtures\Doctrine\EntityManagerFactory;
use Sylament\Tables\Tests\Fixtures\Doctrine\RecordingMiddleware;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;

pest()
    ->extend(TestCase::class)
    ->in('Integration');

pest()
    ->afterEach(function (): void {
        ComponentManager::unsetInstance();
        ComponentResolver::unsetInstance();
    })
    ->in('Unit');

/**
 * Render a real component through the booted kernel's Twig and return the HTML.
 * A single render exercises the fluent API, state resolution, closure evaluation
 * through the real Invoker, the Twig templates, and the registered extensions at once.
 */
function renderToHtml(ViewComponent $component): string
{
    return $component->render(test()->getContainer()->get('twig')) ?? '';
}

/**
 * Attach a filter to a table whose host carries the applied and form filter-state
 * bags, keyed by filter name — the shape the host's filter concern hydrates from
 * its live props.
 *
 * @template TFilter of BaseFilter
 * @param TFilter $filter
 * @param array<string, array<string, mixed>> $state
 * @param array<string, array<string, mixed>> $formState
 * @return TFilter
 */
function attachFilter(BaseFilter $filter, array $state = [], array $formState = []): BaseFilter
{
    return $filter->table(Table::make(new FilterStateHost(filterState: $state, filterFormState: $formState)));
}

/**
 * A relation-scoped table built outside the container, so the association reader
 * the `#[Required]` setter would have injected is set by hand over a fresh
 * in-memory rig — the schemas Unit precedent.
 */
function relationshipTable(Closure $relationship, ?Closure $configure = null): Table
{
    $entityManager = EntityManagerFactory::create();
    $table = new TableHost(fn (Table $table): Table => ($configure ?? fn (Table $table): Table => $table)($table->relationship($relationship)))->getTable();
    $table->setAssociationReader(new AssociationReader($entityManager, $table->getPropertyAccessor()));

    return $table;
}

/**
 * Two songs and four genres — Rock (1), Pop (2), Jazz (3), Metal (4) — with
 * song 1 carrying Rock, Pop and Jazz and song 2 Jazz and Metal, attached
 * through {@see Song::addGenre()}; the identity map is left intact.
 *
 * @return array{KernelBrowser, EntityManagerInterface, RecordingMiddleware}
 */
function seededGenresClient(): array
{
    $client = test()->getContainer()->get('test.client');
    $client->disableReboot();

    $entityManager = test()->getContainer()->get(EntityManagerInterface::class);
    $recorder = test()->getContainer()->get(RecordingMiddleware::class);

    $genres = [new Genre(1, 'Rock'), new Genre(2, 'Pop'), new Genre(3, 'Jazz'), new Genre(4, 'Metal')];
    $songs = [new Song(1, 'Airbag', 100), new Song(2, 'Paranoid Android', 200)];

    foreach ([...$genres, ...$songs] as $entity) {
        $entityManager->persist($entity);
    }

    $songs[0]->addGenre($genres[0])->addGenre($genres[1])->addGenre($genres[2]);
    $songs[1]->addGenre($genres[2])->addGenre($genres[3]);
    $entityManager->flush();
    $recorder->reset();

    return [$client, $entityManager, $recorder];
}

/**
 * A GET of the browsing-state fixture page, which renders the url-bound and the
 * unbound host over one table. `test.client` is not a shared service, so a test
 * needing two requests in one session must hand the first client back in — a
 * second `get()` would come with an empty cookie jar.
 *
 * @return array{KernelBrowser, Crawler}
 */
function browseTo(string $query = '', ?KernelBrowser $client = null): array
{
    $client ??= test()->getContainer()->get('test.client');
    $client->disableReboot();

    return [$client, $client->request('GET', '/fixtures/browsing-state' . $query)];
}

/**
 * The props one host published, decoded off its live root.
 *
 * @return array<string, mixed>
 */
function publishedProps(Crawler $crawler, string $selector): array
{
    $props = json_decode($crawler->filter($selector . ' [data-live-props-value]')->attr('data-live-props-value') ?? '', true, flags: JSON_THROW_ON_ERROR);

    expect($props)->toBeArray();
    assert(is_array($props));

    /** @var array<string, mixed> $props */
    return $props;
}

/**
 * The text one host rendered, its own live root and everything under it.
 */
function renderedRowsOf(Crawler $crawler, string $componentName): string
{
    return $crawler->filter(sprintf('[data-live-name-value="%s"]', $componentName))->text();
}
