<?php

declare(strict_types=1);

use Sylament\Tables\Tests\Fixtures\Controller\BrowsingStatePageController;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

return static function (RoutingConfigurator $routes): void {
    $routes->add('tables_fixtures_browsing_state', '/fixtures/browsing-state')
        ->controller(BrowsingStatePageController::class);
};
