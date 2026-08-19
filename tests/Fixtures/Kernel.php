<?php

declare(strict_types=1);

namespace Sylament\Tables\Tests\Fixtures;

use Sylament\Actions\SylamentActionsBundle;
use Sylament\Forms\SylamentFormsBundle;
use Sylament\Notifications\SylamentNotificationsBundle;
use Sylament\Schemas\SylamentSchemasBundle;
use Sylament\Support\SylamentSupportBundle;
use Sylament\Tables\SylamentTablesBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use Symfony\UX\Icons\UXIconsBundle;
use Symfony\UX\LiveComponent\LiveComponentBundle;
use Symfony\UX\StimulusBundle\StimulusBundle;
use Symfony\UX\TwigComponent\TwigComponentBundle;

class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    public function registerBundles(): iterable
    {
        return [
            new FrameworkBundle(),
            new TwigBundle(),
            new TwigComponentBundle(),
            new UXIconsBundle(),
            new StimulusBundle(),
            new LiveComponentBundle(),

            new SylamentActionsBundle(),
            new SylamentFormsBundle(),
            new SylamentNotificationsBundle(),
            new SylamentSchemasBundle(),
            new SylamentSupportBundle(),
            new SylamentTablesBundle(),
        ];
    }

    private function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import('@LiveComponentBundle/config/routes.php');
    }

    private function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', [
            'secret' => 'SECRET',
            'test' => true,
            'session' => [
                'storage_factory_id' => 'session.storage.factory.mock_file',
            ],
        ]);

        $container->extension('ux_icons', [
            'icon_dir' => __DIR__ . '/assets/icons',
            'iconify' => [
                'enabled' => false,
            ],
        ]);

        $container->extension('twig', [
            'paths' => [
                __DIR__ . '/Twig/templates' => 'TablesFixtures',
            ],
        ]);

        $container->extension('twig_component', [
            'anonymous_template_directory' => 'components',
            'defaults' => [],
        ]);

        $container->import(__DIR__ . '/services_doctrine.php');

        $container->services()
            ->defaults()
                ->autowire()
                ->autoconfigure()
            ->load('Sylament\\Tables\\Tests\\Fixtures\\', __DIR__ . '/*')
                ->exclude([
                    __DIR__ . '/Kernel.php',
                    __DIR__ . '/services_doctrine.php',
                ]);
    }
}
