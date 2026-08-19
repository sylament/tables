<?php

declare(strict_types=1);

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Sylament\Tables\Tests\Fixtures\Doctrine\EntityManagerFactory;
use Sylament\Tables\Tests\Fixtures\Doctrine\FixtureManagerRegistry;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    // Container-wide, so every test in this kernel gets it — not only the Doctrine ones:
    // schemas wires `sylament_schemas.association_reader` with `nullOnInvalid()`, so this
    // registration is what makes that reader live here instead of null.
    $services->set(EntityManagerInterface::class)
        ->factory(EntityManagerFactory::create(...))
        ->public();

    $services->alias(ManagerRegistry::class, FixtureManagerRegistry::class);
};
