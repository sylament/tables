<?php

declare(strict_types=1);

namespace Sylament\Tables\Tests\Integration\Twig\Extension;

use RuntimeException;
use stdClass;
use Sylament\Tables\Twig\Extension\TableExtension;
use Twig\Environment;
use Twig\Markup;

/*
 * The container-side half of `renderTable()`: the unit suite drives the
 * extension against an `ArrayLoader` stub, this one renders a real host
 * component through the booted kernel's Twig, so the shipped `Table` partial
 * and the fixture host's `<twig:SongsTable />` invocation are both in the loop.
 */

function tableTwig(): Environment
{
    $twig = test()->getContainer()->get('twig');

    assert($twig instanceof Environment);

    return $twig;
}

it('renders the host table through renderTable()', function (): void {
    $html = tableTwig()->render('@TablesFixtures/table_extension_page.html.twig');

    expect($html)->toContain('sy-ta-table')->toContain('Karma Police');
});

it('refuses a context whose component is not a table host', function (): void {
    $twig = tableTwig();
    $extension = $twig->getExtension(TableExtension::class);

    expect(fn (): ?Markup => $extension->renderTable($twig, ['this' => new stdClass()]))
        ->toThrow(RuntimeException::class);
});
