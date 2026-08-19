<?php

declare(strict_types=1);

namespace Sylament\Tables\Tests\Fixtures\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\AbstractManagerRegistry;

/**
 * The single-manager registry a Doctrine-less test kernel has no bundle to
 * supply — enough for `getManagerForClass()`, which is all a `DeleteAction`
 * asks of it. The proxy interface is null because the fixture entity manager
 * runs on native lazy objects.
 */
final class FixtureManagerRegistry extends AbstractManagerRegistry
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
        parent::__construct('fixture', [], ['default' => 'default'], 'default', 'default');
    }

    protected function getService(string $name): EntityManagerInterface
    {
        return $this->entityManager;
    }

    protected function resetService(string $name): void
    {
    }
}
