<?php

declare(strict_types=1);

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Sylament\Tables\Tests\Fixtures\Doctrine\EntityManagerFactory;
use Sylament\Tables\Tests\Fixtures\Doctrine\FixtureManagerRegistry;
use Sylament\Tables\Tests\Fixtures\Doctrine\RecordingMiddleware;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    // The recorder rides along on the container-wide manager so an Integration
    // test can read the SQL — and the transaction markers — of a live round trip,
    // which the per-test managers the Unit suite builds cannot span.
    $services->set(RecordingMiddleware::class)->public();

    // Container-wide, so every test in this kernel gets it — not only the Doctrine ones:
    // schemas wires `sylament_schemas.association_reader` with `nullOnInvalid()`, so this
    // registration is what makes that reader live here instead of null.
    $services->set(EntityManagerInterface::class)
        ->factory(EntityManagerFactory::create(...))
        ->args([service(RecordingMiddleware::class)])
        ->public();

    $services->alias(ManagerRegistry::class, FixtureManagerRegistry::class);
};
