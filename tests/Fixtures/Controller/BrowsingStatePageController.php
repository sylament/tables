<?php

declare(strict_types=1);

namespace Sylament\Tables\Tests\Fixtures\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;

/**
 * A real routed page rendering both browsing-state hosts, because the query
 * string only reaches a live component through the main request — a component
 * mounted outside one is never seeded, and its session hook has no session.
 */
final class BrowsingStatePageController extends AbstractController
{
    public function __invoke(): Response
    {
        return $this->render('@TablesFixtures/browsing_state_page.html.twig');
    }
}
