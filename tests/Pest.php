<?php

declare(strict_types=1);

namespace Sylament\Tables\Tests;

use Sylament\Support\Components\ViewComponent;
use Sylament\Support\Configuration\ComponentManager;
use Sylament\Support\DependencyInjection\ComponentResolver;
use Sylament\Tables\Filters\BaseFilter;
use Sylament\Tables\Table;
use Sylament\Tables\Tests\Fixtures\Concerns\FilterStateHost;

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
