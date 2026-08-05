<?php

declare(strict_types=1);

namespace Sylament\Tables\Tests\Unit\Twig\Extension;

use RuntimeException;
use stdClass;
use Sylament\Support\Twig\Extension\ComponentExtension;
use Sylament\Tables\Tests\Fixtures\Concerns\TableHost;
use Sylament\Tables\Twig\Extension\TableExtension;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\Markup;

function tableExtension(): TableExtension
{
    return new TableExtension(new ComponentExtension());
}

function tableTwig(): Environment
{
    $twig = new Environment(new ArrayLoader([
        '@SylamentTables/partials/Table/index.html.twig' => 'table:{{ component.getEmptyStateHeading }}',
    ]));
    $twig->addExtension(new ComponentExtension());

    return $twig;
}

it('registers the renderTable function', function (): void {
    $functions = tableExtension()->getFunctions();

    expect($functions)->toHaveCount(1)
        ->and($functions[0]->getName())->toBe('renderTable');
});

it('renders the table of the component in context', function (): void {
    $rendered = tableExtension()->renderTable(tableTwig(), ['this' => new TableHost()]);

    expect($rendered)->toBeInstanceOf(Markup::class)
        ->and((string) $rendered)->toBe('table:No records');
});

it('throws when the context has no rendering component', function (): void {
    expect(fn (): ?Markup => tableExtension()->renderTable(new Environment(new ArrayLoader()), []))
        ->toThrow(RuntimeException::class);
});

it('throws when the rendering component does not interact with a table', function (): void {
    expect(fn (): ?Markup => tableExtension()->renderTable(new Environment(new ArrayLoader()), ['this' => new stdClass()]))
        ->toThrow(RuntimeException::class);
});
