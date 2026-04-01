<?php

declare(strict_types=1);

namespace App\Bundle\DbMapperBundle\Service;

use App\Bundle\DbMapperBundle\Exception\SchemaSynchronizationException;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;

class SchemaSynchronizer
{
    private EntityManagerInterface $entityManager;

    public function __construct(EntityManagerInterface $entityManager)
    {
        $this->entityManager = $entityManager;
    }

    /** @return array<int, string> */
    public function getPendingSql(): array
    {
        $metadata = $this->entityManager->getMetadataFactory()->getAllMetadata();

        if (empty($metadata)) {
            return [];
        }

        return (new SchemaTool($this->entityManager))->getUpdateSchemaSql($metadata, false);
    }

    /**
     * Applique les instructions SQL en attente et retourne celles exécutées.
     *
     * @return array<int, string>
     * @throws SchemaSynchronizationException
     */
    public function synchronize(): array
    {
        $sqlStatements = $this->getPendingSql();

        if (empty($sqlStatements)) {
            return [];
        }

        /** @var Connection $connection */
        $connection = $this->entityManager->getConnection();
        $isMysql    = $connection->getDatabasePlatform() instanceof MySQLPlatform;

        try {
            if ($isMysql) {
                $connection->executeStatement('SET FOREIGN_KEY_CHECKS=0');
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
            if ($isMysql) {
                $connection->executeStatement('SET FOREIGN_KEY_CHECKS=1');
            }
        }

        return $sqlStatements;
    }
}
