<?php

declare(strict_types=1);

namespace Sylament\Tables;

use Sylament\Support\SylamentBundle;
use Sylament\Tables\Twig\Extension\TableExtension;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

class SylamentTablesBundle extends SylamentBundle
{
    /**
     * @param array<string, mixed> $config
     */
    public function loadExtension(array $config, ContainerConfigurator $configurator, ContainerBuilder $container): void
    {
        $services = $configurator->services();

        $services
            ->load(__NAMESPACE__ . '\\Columns\\', __DIR__ . '/Columns/*.php')
            ->autowire()
            ->autoconfigure();

        $services
            ->load(__NAMESPACE__ . '\\Filters\\', __DIR__ . '/Filters/*.php')
            ->exclude([__DIR__ . '/Filters/FilterIndicator.php'])
            ->autowire()
            ->autoconfigure();

        // `ColorManager` resolves a policy from the service container by class-string, and a
        // private service is not reachable through `Container::get()`, so both globs stay public.
        $services
            ->load(__NAMESPACE__ . '\\Colors\\Components\\', __DIR__ . '/Colors/Components/*/*.php')
            ->autowire()
            ->public();

        $services
            ->load(__NAMESPACE__ . '\\Colors\\Components\\', __DIR__ . '/Colors/Components/*/*/*.php')
            ->autowire()
            ->public();

        $services
            ->set(Table::class)
            ->autowire()
            ->autoconfigure();

        $services
            ->set('sylament_tables.table_extension', TableExtension::class)
            ->autowire()
            ->tag('twig.extension');
    }

    public function prependExtension(ContainerConfigurator $configurator, ContainerBuilder $container): void
    {
        $this->prependAssetMapper($container);
    }

    private function prependAssetMapper(ContainerBuilder $container): void
    {
        if (!$this->isAssetMapperAvailable($container)) {
            return;
        }

        $container->prependExtensionConfig('framework', [
            'asset_mapper' => [
                'paths' => [
                    __DIR__ . '/../assets/dist' => '@sylament/tables',
                ],
            ],
        ]);
    }
}
