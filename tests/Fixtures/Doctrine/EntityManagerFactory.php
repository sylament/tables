<?php

declare(strict_types=1);

namespace Sylament\Tables\Tests\Fixtures\Doctrine;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;

final class EntityManagerFactory
{
    /**
     * An in-memory SQLite entity manager with the fixture schema already built,
     * counting every statement it runs when given a recorder.
     */
    public static function create(?RecordingMiddleware $recorder = null): EntityManager
    {
        if (!Type::hasType(HandleType::NAME)) {
            Type::addType(HandleType::NAME, HandleType::class);
        }

        $config = ORMSetup::createAttributeMetadataConfig(paths: [__DIR__ . '/Entity'], isDevMode: true);
        $config->enableNativeLazyObjects(true);

        if ($recorder instanceof RecordingMiddleware) {
            $config->setMiddlewares([$recorder]);
        }

        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $config);
        $entityManager = new EntityManager($connection, $config);

        new SchemaTool($entityManager)->createSchema($entityManager->getMetadataFactory()->getAllMetadata());

        return $entityManager;
    }

    /**
     * Clears the identity map after flushing, so a subsequent read goes to the
     * database rather than answering from memory.
     */
    public static function seed(EntityManager $entityManager, object ...$entities): void
    {
        foreach ($entities as $entity) {
            $entityManager->persist($entity);
        }

        $entityManager->flush();
        $entityManager->clear();
    }
}
