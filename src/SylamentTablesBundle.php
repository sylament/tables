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
            ->set(Table::class)
            ->autowire()
            ->autoconfigure();

        $services
            ->set('sylament_tables.table_extension', TableExtension::class)
            ->autowire()
            ->tag('twig.extension');
    }
}
