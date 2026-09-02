<?php

declare(strict_types=1);

namespace Sylament\Tables\Tests\Fixtures\Twig\Components;

use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;

/**
 * The card-shaped host: the same table on the same page, opting into nothing —
 * no query-string binding and no session persistence, which is what every
 * showcase card and every relation manager is.
 */
#[AsLiveComponent(name: 'UnboundSongsTable', template: '@TablesFixtures/components/UnboundSongsTable.html.twig')]
final class UnboundSongsTable extends BrowsingStateTable
{
}
