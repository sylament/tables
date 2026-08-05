<?php

declare(strict_types=1);

namespace Sylament\Tables\Twig\Extension;

use RuntimeException;
use Sylament\Support\Twig\Extension\ComponentExtension;
use Sylament\Tables\Contracts\InteractsWithTable;
use Twig\Environment;
use Twig\Extension\AbstractExtension;
use Twig\Markup;
use Twig\TwigFunction;

class TableExtension extends AbstractExtension
{
    public function __construct(
        private readonly ComponentExtension $componentExtension,
    ) {
    }

    /**
     * @return array<TwigFunction>
     */
    public function getFunctions(): array
    {
        return [
            new TwigFunction('renderTable', $this->renderTable(...), [
                'needs_environment' => true,
                'needs_context' => true,
            ]),
        ];
    }

    /**
     * @param array<mixed> $context
     */
    public function renderTable(Environment $twig, array $context): ?Markup
    {
        $component = $context['this'] ?? null;
        if (!$component instanceof InteractsWithTable) {
            throw new RuntimeException('Component must be an instance of ' . InteractsWithTable::class);
        }

        return $this->componentExtension->renderComponent($twig, $component->getTable());
    }
}
