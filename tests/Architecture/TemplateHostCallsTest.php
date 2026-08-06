<?php

declare(strict_types=1);

namespace Sylament\Tables\Tests\Architecture;

use Sylament\Tables\Contracts\InteractsWithTable;

use function dirname;

/**
 * A tables template reaches its host through `component.getLiveComponent`, which
 * is typed `InteractsWithTable`. Every method read off it must therefore be
 * declared on the contract: a method that only exists on the `HasTable` trait
 * renders fine for a trait-using host and fatals in Twig for any other host
 * satisfying the published interface.
 *
 * @return list<array{string, string}> template path (relative to packages/) and host method name
 */
function tablesTemplateHostCalls(): array
{
    $packagesDirectory = dirname(__DIR__, 3);

    $calls = [];

    foreach (glob($packagesDirectory . '/tables/templates/*/*/*.html.twig') ?: [] as $template) {
        preg_match_all('/getLiveComponent\.(\w+)/', (string) file_get_contents($template), $matches);

        foreach (array_unique($matches[1]) as $method) {
            $calls[] = [str_replace($packagesDirectory . '/', '', $template), $method];
        }
    }

    return $calls;
}

it('reads only contract methods off the table host', function (string $template, string $method): void {
    expect(method_exists(InteractsWithTable::class, $method))->toBeTrue(
        "{$template} calls {$method}() on component.getLiveComponent, which is typed "
        . 'InteractsWithTable, but the contract does not declare it. Declare it there, or read the '
        . 'value off something the template already has.',
    );
})->with(tablesTemplateHostCalls());
