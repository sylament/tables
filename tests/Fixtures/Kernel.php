<?php

declare(strict_types=1);

namespace Sylament\Tables\Tests\Fixtures;

use League\FlysystemBundle\FlysystemBundle;
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
            new FlysystemBundle(),

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
        // Ahead of the live route, whose `/{_live_component}/{_live_action}` matches
        // any two-segment path — including the fixture page's.
        $routes->import(__DIR__ . '/routes_fixtures.php');
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

        // Two local storages under the cache directory, mirroring forms' fixture kernel:
        // `test.storage` is the column's default and the only one that can mint a URL, since
        // `public_url` is what gives a local adapter one at all; `other.storage` is the no-URL arm.
        $container->extension('flysystem', [
            'storages' => [
                'test.storage' => [
                    'local' => [
                        'directory' => $this->getCacheDir() . '/storage/test',
                    ],
                    'visibility' => 'public',
                    'directory_visibility' => 'public',
                    'public_url' => '/files',
                ],
                'other.storage' => [
                    'local' => [
                        'directory' => $this->getCacheDir() . '/storage/other',
                    ],
                ],
            ],
        ]);

        $container->extension('sylament', [
            'default_filesystem_disk' => 'test.storage',
        ]);

        $container->import(__DIR__ . '/services_doctrine.php');

        $container->services()
            ->defaults()
                ->autowire()
                ->autoconfigure()
            ->load('Sylament\\Tables\\Tests\\Fixtures\\', __DIR__ . '/*')
                ->exclude([
                    __DIR__ . '/Kernel.php',
                    __DIR__ . '/routes_fixtures.php',
                    __DIR__ . '/services_doctrine.php',
                ]);
    }
}
