<?php

declare(strict_types=1);

namespace App\Bundle\DbMapperBundle\Service;

use App\Bundle\DbMapperBundle\Exception\SchemaSynchronizationException;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;

/**
 * Provides Doctrine schema diff and synchronization helpers.
 */
class SchemaSynchronizer
{
    private EntityManagerInterface $entityManager;

    public function __construct(EntityManagerInterface $entityManager)
    {
        $this->entityManager = $entityManager;
    }

    /**
     * Returns pending SQL statements required to sync the Doctrine mapping with the actual database.
     *
     * @return array<int, string>
     */
    public function getPendingSql(): array
    {
        $metadata = $this->entityManager->getMetadataFactory()->getAllMetadata();

        if (empty($metadata)) {
            return [];
        }

        $schemaTool = new SchemaTool($this->entityManager);

        return $schemaTool->getUpdateSchemaSql($metadata, false);
    }

    /**
     * Applies the pending SQL statements and returns the executed queries.
     *
     * @return array<int, string>
     */
    public function synchronize(): array
    {
        $sqlStatements = $this->getPendingSql();

        if (empty($sqlStatements)) {
            return [];
        }

        $connection = $this->entityManager->getConnection();
        \assert($connection instanceof Connection);

        $foreignKeysTemporarilyDisabled = false;

        try {
            if ($connection->getDatabasePlatform() instanceof MySQLPlatform) {
                $connection->executeStatement('SET FOREIGN_KEY_CHECKS=0');
                $foreignKeysTemporarilyDisabled = true;
            }

            foreach ($sqlStatements as $sql) {
                $connection->executeStatement($sql);
            }
        } catch (\Throwable $exception) {
            throw new SchemaSynchronizationException(
                sprintf('Failed to synchronize schema: %s', $exception->getMessage()),
                0,
                $exception
            );
        } finally {
            if ($foreignKeysTemporarilyDisabled) {
                $connection->executeStatement('SET FOREIGN_KEY_CHECKS=1');
            }
        }

        return $sqlStatements;
    }
}
